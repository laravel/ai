<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Throwable;

use function Laravel\Ai\pipeline;

trait StreamsText
{
    use ResumesToolApprovals;

    /**
     * Stream the response from the given agent.
     */
    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        $invocationId = $prompt->invocationId ?? (string) Str::uuid7();

        $resolvedApprovalResults = null;

        try {
            $response = pipeline()
                ->send($prompt)
                ->through($this->gatherMiddlewareFor($prompt->agent))
                ->then(function (AgentPrompt $prompt) use ($invocationId, &$resolvedApprovalResults): StreamableAgentResponse {
                    $agent = $prompt->agent;

                    if ($agent instanceof HasStructuredOutput) {
                        throw new InvalidArgumentException('Streaming structured output is not currently supported.');
                    }

                    $meta = new Meta($this->name(), $prompt->model);

                    $messages = $this->withoutForeignProviderContentBlocks([
                        ...($prompt->messages ?? []),
                        ...($agent instanceof Conversational ? $agent->messages() : []),
                    ]);

                    if (! $prompt->hasApprovalDecisions()) {
                        $messages[] = new UserMessage($prompt->prompt, $prompt->attachments->all());
                    }

                    $tools = $this->resolveTools($prompt);
                    $approval = $this->resumableApprovalFor($prompt);
                    $recordApprovalResults = $this->approvalResultRecorderFor($prompt, $resolvedApprovalResults);

                    // Validate eagerly so a mismatch throws before the stream begins, then thread the result into stream() so it isn't re-validated there...
                    $validatedApproval = $approval !== null
                        ? $this->textGenerationLoop()->validateApproval($approval, $messages, $tools)
                        : null;

                    $streamable = null;

                    // The response owns the "has anything reached the consumer" flag so this failure check and the caller's failover decision can never drift apart...
                    $streamable = new StreamableAgentResponse(
                        $invocationId,
                        function () use ($invocationId, $prompt, $agent, $messages, $tools, $approval, $recordApprovalResults, $validatedApproval, &$streamable) {
                            $this->events->dispatch(new StreamingAgent($invocationId, $prompt));

                            try {
                                foreach ($this->textGenerationLoop()->stream(
                                    $invocationId,
                                    $this,
                                    $prompt->model,
                                    (string) $agent->instructions(),
                                    $messages,
                                    $tools,
                                    null,
                                    TextGenerationOptions::forAgent($agent),
                                    $prompt->timeout,
                                    $approval,
                                    $recordApprovalResults,
                                    $validatedApproval,
                                    $this->runContextFor($invocationId, $prompt),
                                ) as $event) {
                                    if ($event instanceof ToolApprovalRequest) {
                                        $this->throwIfNotResumable($prompt);
                                    }

                                    yield $event;
                                }
                            } catch (Throwable $exception) {
                                $this->recordAgentFailure($invocationId, $prompt, $exception, retryable: ! $streamable->hasYielded());

                                throw $exception;
                            }
                        },
                        $meta,
                    );

                    // Surfaced before iteration because the remembering middleware only records it once the stream has drained...
                    if ($agent instanceof RemembersConversationsContract || in_array(RemembersConversations::class, class_uses_recursive($agent), true)) {
                        /** @var Agent&RemembersConversationsContract $agent */
                        if ($agent->currentConversation() !== null) {
                            $streamable->withinConversation(
                                $agent->currentConversation(),
                                $agent->conversationParticipant(),
                            );
                        }
                    }

                    return $streamable;
                });
        } catch (Throwable $exception) {
            $this->recordAgentFailure($invocationId, $prompt, $exception);

            throw $exception;
        }

        return $response->then(function (StreamedAgentResponse $response) use ($invocationId, $prompt, &$resolvedApprovalResults): void {
            $this->events->dispatch(
                new AgentStreamed($invocationId, $prompt, $response)
            );

            if ($response->hasPendingApprovals()) {
                $this->events->dispatch(new ToolApprovalRequested(
                    $invocationId,
                    $prompt->agent,
                    $response->pendingApprovals,
                    $response->conversationId,
                    $response->conversationUser,
                ));
            }

            if ($resolvedApprovalResults !== null) {
                $this->events->dispatch(new ToolApprovalResolved(
                    $invocationId,
                    $prompt->agent,
                    $resolvedApprovalResults,
                    $response->conversationId,
                    $response->conversationUser,
                ));
            }
        });
    }
}
