<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Data\Meta;
use Laravel\Ai\Data\Usage;
use Laravel\Ai\Events\AgentInvoked;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;

use function Laravel\Ai\ulid;

class FakeProvider extends Provider implements TextProvider
{
    protected ?TextProvider $originalProvider = null;

    protected ?string $originalModel = null;

    protected int $currentResponseIndex = 0;

    public function __construct(
        protected array $responses,
        protected Dispatcher $events
    ) {}

    /**
     * Invoke the given agent.
     */
    public function prompt(Agent $agent, string $prompt, array $attachments, string $model): AgentResponse
    {
        $invocationId = (string) Str::uuid7();

        $this->events->dispatch(
            new InvokingAgent($invocationId, $this, $model, $agent, $prompt)
        );

        $response = $this->nextResponse();

        $this->events->dispatch(
            new AgentInvoked($invocationId, $this, $model, $agent, $prompt, $response)
        );

        return $response;
    }

    /**
     * Stream the response from the given agent.
     */
    public function stream(Agent $agent, string $prompt, array $attachments, string $model): StreamableAgentResponse
    {
        $invocationId = (string) Str::uuid7();

        return new StreamableAgentResponse($invocationId, function () use ($invocationId, $agent, $prompt, $model) {
            if ($agent instanceof HasStructuredOutput) {
                throw new InvalidArgumentException('Streaming structured output is not currently supported.');
            }

            $this->events->dispatch(
                new StreamingAgent($invocationId, $this, $model, $agent, $prompt)
            );

            $messageId = ulid();

            yield new StreamStart(ulid(), $this->providerName(), $model, time());
            yield new TextStart(ulid(), $messageId, time());

            $fakeResponse = $this->nextResponse();

            $events = Str::of($fakeResponse->text)
                ->explode(' ')
                ->map(fn ($word, $index) => new TextDelta(
                    ulid(),
                    $messageId,
                    $index > 0 ? ' '.$word : $word,
                    time(),
                ))->all();

            foreach ($events as $event) {
                yield $event;
            }

            yield new TextEnd(ulid(), $messageId, time());
            yield new StreamEnd(ulid(), 'stop', new Usage, time());

            $response = new StreamedAgentResponse(
                $invocationId,
                collect($events),
                new Meta($this->providerName(), $model),
            );

            $this->events->dispatch(
                new AgentStreamed($invocationId, $this, $model, $agent, $prompt, $response)
            );
        });
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(): mixed
    {
        return tap($this->responses[$this->currentResponseIndex], function () {
            $this->currentResponseIndex++;
        });
    }

    /**
     * Set the original provider and model.
     */
    public function withOriginalProvider(TextProvider $provider, string $model): self
    {
        $this->originalProvider = $provider;
        $this->originalModel = $model;

        return $this;
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'laravel/fake-text';
    }

    /**
     * Get the name of the underlying AI provider.
     */
    public function providerName(): string
    {
        return 'fake';
    }

    /**
     * Get the credentials for the underlying AI provider.
     */
    public function providerCredentials(): array
    {
        return [];
    }
}
