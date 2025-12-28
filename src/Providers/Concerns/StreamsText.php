<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\AgentPrompt;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Data\Meta;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;

trait StreamsText
{
    /**
     * Stream the response from the given agent.
     */
    public function stream(Agent $agent, string $prompt, array $attachments, string $model): StreamableAgentResponse
    {
        $invocationId = (string) Str::uuid7();

        return new StreamableAgentResponse($invocationId, function () use ($invocationId, $agent, $prompt, $attachments, $model) {
            if ($agent instanceof HasStructuredOutput) {
                throw new InvalidArgumentException('Streaming structured output is not currently supported.');
            }

            $this->events->dispatch(new StreamingAgent($invocationId, $agentPrompt = new AgentPrompt(
                $agent, $prompt, $attachments, $this, $model
            )));

            $messages = $agent instanceof Conversational ? $agent->messages() : [];

            $messages[] = new UserMessage($prompt, $attachments);

            $events = [];

            $this->listenForToolInvocations($invocationId, $agent);

            foreach ($this->textGateway()->streamText(
                $invocationId,
                $this,
                $model,
                (string) $agent->instructions(),
                $messages,
                $agent instanceof HasTools ? $agent->tools() : [],
                $agent instanceof HasStructuredOutput ? $agent->schema(new JsonSchemaTypeFactory) : null,
            ) as $event) {
                $events[] = $event;

                yield $event;
            }

            $response = new StreamedAgentResponse(
                $invocationId,
                collect($events),
                new Meta($this->providerName(), $model),
            );

            $this->events->dispatch(
                new AgentStreamed($invocationId, $agentPrompt, $response)
            );
        });
    }
}
