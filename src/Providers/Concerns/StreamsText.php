<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;

use function Laravel\Ai\pipeline;

trait StreamsText
{
    /**
     * Stream the response from the given agent.
     */
    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        $invocationId = (string) Str::uuid7();

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

                return new StreamableAgentResponse(
                    $invocationId,
                    function () use ($invocationId, $prompt, $agent) {
                        $this->events->dispatch(new StreamingAgent($invocationId, $prompt));

                        $messages = $agent instanceof Conversational ? $agent->messages() : [];

                        $messages[] = new UserMessage($prompt->prompt, $prompt->attachments->all());

                        $this->listenForToolInvocations($invocationId, $agent);

                        yield from $this->textGateway()->streamText(
                            $invocationId,
                            $this,
                            $prompt->model,
                            (string) $agent->instructions(),
                            $messages,
                            $agent instanceof HasTools ? $agent->tools() : [],
                            null,
                            TextGenerationOptions::forAgent($agent),
                        );
                    },
                    $meta,
                );
            })->then(function (StreamedAgentResponse $response) use ($invocationId, &$processedPrompt) {
                $this->events->dispatch(
                    new AgentStreamed($invocationId, $processedPrompt, $response)
                );
            });
    }
}
