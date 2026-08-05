<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Exceptions\FailoverableException;
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

        $processedPrompt = null;
        $resolvedApprovalResults = null;

        try {
            $response = pipeline()
                ->send($prompt)
                ->through($this->gatherMiddlewareFor($prompt->agent))
                ->then(function (AgentPrompt $prompt) use ($invocationId, &$processedPrompt, &$resolvedApprovalResults): StreamableAgentResponse {
                    $processedPrompt = $prompt;

                    $agent = $prompt->agent;

                    if ($agent instanceof HasStructuredOutput) {
                        throw new InvalidArgumentException('Streaming structured output is not currently supported.');
                    }

                    $meta = new Meta($this->name(), $prompt->model);

                    $messages = $this->withoutForeignProviderContentBlocks([
                        ...($agent instanceof Conversational ? $agent->messages() : []),
                    ]);

                    if (! $prompt->hasApprovalDecisions()) {
                        $messages[] = new UserMessage($prompt->prompt, $prompt->attachments->all());
                    }

                    $tools = $this->resolveTools($agent);
                    $approval = $this->resumableApprovalFor($prompt);
                    $recordApprovalResults = $this->approvalResultRecorderFor($prompt, $resolvedApprovalResults);

                    // Validate eagerly so a mismatch throws before the stream begins, then thread the result into stream() so it isn't re-validated there...
                    $validatedApproval = $approval !== null
                        ? $this->textGenerationLoop()->validateApproval($approval, $messages, $tools)
                        : null;

                    return new StreamableAgentResponse(
                        $invocationId,
                        function () use ($invocationId, $prompt, $agent, $messages, $tools, $approval, $recordApprovalResults, $validatedApproval) {
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
                                    $this->observersFor($invocationId, $prompt),
                                ) as $event) {
                                    if ($event instanceof ToolApprovalRequest) {
                                        $this->throwIfNotResumable($agent);
                                    }

                                    yield $event;
                                }
                            } catch (Throwable $exception) {
                                // A failoverable exception is only terminal once the caller has run out of providers to try...
                                if (! $exception instanceof FailoverableException || ! $prompt->canFailOver) {
                                    $this->events->dispatch(new AgentFailed($invocationId, $prompt, $exception));
                                }

                                throw $exception;
                            }
                        },
                        $meta,
                    );
                });
        } catch (Throwable $exception) {
            // A failoverable exception is only terminal once the caller has run out of providers to try...
            if (! $exception instanceof FailoverableException || ! $prompt->canFailOver) {
                $this->events->dispatch(
                    new AgentFailed($invocationId, $processedPrompt ?? $prompt, $exception)
                );
            }

            throw $exception;
        }

        return $response->then(function (StreamedAgentResponse $response) use ($invocationId, $prompt, &$processedPrompt, &$resolvedApprovalResults): void {
            $this->events->dispatch(
                new AgentStreamed($invocationId, $processedPrompt ?? $prompt, $response)
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
