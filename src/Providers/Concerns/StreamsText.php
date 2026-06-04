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
        $context = InvocationContext::for($prompt->invocationId ?? (string) Str::uuid7());

        $prompt = $prompt->withInvocationContext($context);

        $processedPrompt = null;

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

                return new StreamableAgentResponse(
                    $context->id,
                    function () use ($context, $prompt, $agent) {
                        // The generator runs lazily, after stream() returns, so re-activate the
                        // context for its lifetime - a sub-agent invoked mid-stream nests beneath
                        // it. Paired with pop() in finally so the stack unwinds on early exit.
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
                            InvocationContext::pop();
                        }
                    },
                    $meta,
                );
            })->then(function (StreamedAgentResponse $response) use ($context, $prompt, &$processedPrompt) {
                $response->withInvocationContext($context);

                $this->events->dispatch(
                    new AgentStreamed($context->id, $processedPrompt ?? $prompt, $response)
                );
            });
    }
}
