<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\InvocationContext;
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
        // Honor a context established upstream (e.g. a multi-provider stream), else nest beneath the active one...
        $context = $prompt->invocationContext()
            ?? InvocationContext::for($prompt->invocationId ?? (string) Str::uuid7());

        $prompt = $prompt->withInvocationContext($context);

        $processedPrompt = null;

        // Activate the context around the middleware pipeline so a middleware that invokes a sub-agent nests beneath this invocation...
        $streamable = InvocationContext::run($context, function () use ($prompt, $context, &$processedPrompt) {
            return pipeline()
                ->send($prompt)
                ->through($this->gatherMiddlewareFor($prompt->agent))
                ->then(function (AgentPrompt $prompt) use ($context, &$processedPrompt) {
                    $processedPrompt = $prompt;

                    $agent = $prompt->agent;

                    if ($agent instanceof HasStructuredOutput) {
                        throw new InvalidArgumentException('Streaming structured output is not currently supported.');
                    }

                    $meta = new Meta($this->name(), $prompt->model);

                    return (new StreamableAgentResponse(
                        $context->id,
                        function () use ($context, $prompt, $agent) {
                            // Re-activate the context for the lazily-consumed generator; pop() this exact context so out-of-order streams unwind correctly...
                            InvocationContext::push($context);

                            try {
                                $this->events->dispatch(new StreamingAgent($context->id, $prompt));

                                $messages = [
                                    ...($agent instanceof Conversational ? $agent->messages() : []),
                                    new UserMessage($prompt->prompt, $prompt->attachments->all()),
                                ];

                                $this->listenForToolInvocations($context->id, $agent);

                                yield from $this->textGateway()->streamText(
                                    $context->id,
                                    $this,
                                    $prompt->model,
                                    (string) $agent->instructions(),
                                    $messages,
                                    $this->resolveTools($agent),
                                    null,
                                    TextGenerationOptions::forAgent($agent),
                                    $prompt->timeout,
                                );
                            } finally {
                                InvocationContext::pop($context);
                            }
                        },
                        $meta,
                    ))->withInvocationContext($context);
                });
        });

        return $streamable->then(function (StreamedAgentResponse $response) use ($context, $prompt, &$processedPrompt) {
            $this->events->dispatch(
                new AgentStreamed($context->id, $processedPrompt ?? $prompt, $response)
            );
        });
    }
}
