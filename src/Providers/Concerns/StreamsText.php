<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
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

        return new StreamableAgentResponse($invocationId, function () use ($invocationId, $prompt) {
            $processedPrompt = null;

            $generator = pipeline()
                ->send($prompt)
                ->through($this->gatherMiddlewareFor($prompt->agent))
                ->then(function (AgentPrompt $prompt) use ($invocationId, &$processedPrompt) {
                    $processedPrompt = $prompt;

                    $agent = $prompt->agent;

                    if ($agent instanceof HasStructuredOutput) {
                        throw new InvalidArgumentException('Streaming structured output is not currently supported.');
                    }

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
                        $agent instanceof HasStructuredOutput ? $agent->schema(new JsonSchemaTypeFactory) : null,
                        TextGenerationOptions::forAgent($agent),
                    );
                });

            $events = [];

            foreach ($generator as $event) {
                $events[] = $event;

                yield $event;
            }

            $response = new StreamedAgentResponse(
                $invocationId,
                collect($events),
                new Meta($this->name(), $processedPrompt->model),
            );

            $this->events->dispatch(
                new AgentStreamed($invocationId, $processedPrompt, $response)
            );
        });
    }
}
