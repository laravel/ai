<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;

use function Laravel\Ai\pipeline;

trait StreamsText
{
    /**
     * Stream the response from the given agent.
     */
    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        $invocationId = $prompt->invocationId ?? (string) Str::uuid7();

        $processedPrompt = null;

        return pipeline()
            ->send($prompt)
            ->through($this->gatherMiddlewareFor($prompt->agent))
            ->then(function (AgentPrompt $prompt) use ($invocationId, &$processedPrompt) {
                $processedPrompt = $prompt;

                $agent = $prompt->agent;

                if ($agent instanceof HasStructuredOutput) {
                    throw new InvalidArgumentException('Streaming structured output is not currently supported.');
                }

                $meta = new Meta($this->name(), $prompt->model);

                $messages = [
                    ...($agent instanceof Conversational ? $agent->messages() : []),
                ];

                if ($prompt->resume === null) {
                    $messages[] = new UserMessage($prompt->prompt, $prompt->attachments->all());
                }

                $tools = $this->resolveTools($agent);
                $approval = $this->resumableApprovalFor($prompt);

                // Validate the approval before the SSE response begins so a mismatch can still render as a 409...
                if ($approval !== null) {
                    $this->textGenerationLoop()->validateApproval($approval, $messages, $tools);
                }

                return new StreamableAgentResponse(
                    $invocationId,
                    function () use ($invocationId, $prompt, $agent, $messages, $tools, $approval) {
                        $this->events->dispatch(new StreamingAgent($invocationId, $prompt));

                        $this->listenForToolInvocations($invocationId, $agent);

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
                        ) as $event) {
                            if ($event instanceof ToolApprovalRequest) {
                                ApprovalNotResumableException::throwUnlessResumable($agent);
                            }

                            yield $event;
                        }
                    },
                    $meta,
                );
            })->then(function (StreamedAgentResponse $response) use ($invocationId, $prompt, &$processedPrompt) {
                $this->events->dispatch(
                    new AgentStreamed($invocationId, $processedPrompt ?? $prompt, $response)
                );

                if ($response->awaitingApproval()) {
                    $this->events->dispatch(new ToolApprovalRequested(
                        $invocationId,
                        $prompt->agent,
                        $response->pendingApprovals,
                        $response->conversationId,
                        $response->conversationUser,
                    ));
                }
            });
    }
}
