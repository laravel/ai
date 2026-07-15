<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;

trait StreamsText
{
    /**
     * Stream the response from the given agent.
     */
    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        $invocationId = $prompt->invocationId ?? (string) Str::uuid7();

        $agent = $prompt->agent;

        if ($agent instanceof HasStructuredOutput) {
            throw new InvalidArgumentException('Streaming structured output is not currently supported.');
        }

        if (Ai::hasFakeGatewayFor($agent::class)) {
            Ai::recordPrompt($prompt);
        }

        $middleware = $this->gatherMiddlewareFor($agent);

        $recorder = $this->conversationRecorderFor($agent);

        $meta = new Meta($this->name(), $prompt->model);

        return (new StreamableAgentResponse(
            $invocationId,
            function () use ($invocationId, $prompt, $agent, $middleware) {
                $this->events->dispatch(new StreamingAgent($invocationId, $prompt));

                $messages = [
                    ...($agent instanceof Conversational ? $agent->messages() : []),
                    new UserMessage($prompt->prompt, $prompt->attachments->all()),
                ];

                $this->listenForToolInvocations($invocationId, $agent);

                yield from $this->textGenerationLoop()->stream(
                    $invocationId,
                    $this,
                    $prompt->model,
                    (string) $agent->instructions(),
                    $messages,
                    $this->resolveTools($agent),
                    null,
                    TextGenerationOptions::forAgent($agent)->withMiddleware($middleware),
                    $prompt->timeout,
                );
            },
            $meta,
        ))->then(function (StreamedAgentResponse $response) use ($invocationId, $prompt, $recorder) {
            if ($recorder !== null) {
                $recorder($prompt, $response);
            }

            $this->events->dispatch(
                new AgentStreamed($invocationId, $prompt, $response)
            );
        });
    }
}
