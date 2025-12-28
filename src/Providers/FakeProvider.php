<?php

namespace Laravel\Ai\Providers;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\AgentPrompt;
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
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use RuntimeException;

use function Laravel\Ai\ulid;

class FakeProvider extends Provider implements TextProvider
{
    protected ?TextProvider $originalProvider = null;

    protected ?string $originalModel = null;

    protected int $currentResponseIndex = 0;

    protected bool $preventStrayPrompts = false;

    public function __construct(
        protected Closure|array $responses,
        protected Dispatcher $events
    ) {}

    /**
     * Invoke the given agent.
     */
    public function prompt(Agent $agent, string $prompt, array $attachments, string $model): AgentResponse
    {
        $invocationId = (string) Str::uuid7();

        $this->events->dispatch(new InvokingAgent($invocationId, $agentPrompt = new AgentPrompt(
            $agent, $prompt, $attachments, $this, $model
        )));

        $response = $this->nextResponse($invocationId, $agentPrompt);

        $this->events->dispatch(
            new AgentInvoked($invocationId, $agentPrompt, $response)
        );

        return $response;
    }

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

            $messageId = ulid();

            // Fake the stream and text starting...
            yield new StreamStart(ulid(), $this->providerName(), $model, time());
            yield new TextStart(ulid(), $messageId, time());

            $fakeResponse = $this->nextResponse($invocationId, $agentPrompt);

            $events = Str::of($fakeResponse->text)
                ->explode(' ')
                ->map(fn ($word, $index) => new TextDelta(
                    ulid(),
                    $messageId,
                    $index > 0 ? ' '.$word : $word,
                    time(),
                ))->all();

            // Fake the text delta events...
            foreach ($events as $event) {
                yield $event;
            }

            // Fake the stream and text ending...
            yield new TextEnd(ulid(), $messageId, time());
            yield new StreamEnd(ulid(), 'stop', new Usage, time());

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

    /**
     * Get the next response instance.
     */
    protected function nextResponse(string $invocationId, AgentPrompt $prompt): mixed
    {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $prompt, $invocationId);

        return tap($this->marshalResponse(
            $response, $invocationId, $prompt
        ), fn () => $this->currentResponseIndex++);
    }

    /**
     * Marshal the given response into a full response instance.
     */
    protected function marshalResponse(
        mixed $response,
        string $invocationId,
        AgentPrompt $prompt): mixed
    {
        if (is_null($response)) {
            if ($this->preventStrayPrompts) {
                throw new RuntimeException('Attempted prompt ['.Str::words($prompt->prompt, 10).'] without a fake agent response.');
            }

            if ($prompt->agent instanceof HasStructuredOutput) {
                throw new RuntimeException('Unable to automatically determine fake response for agents with structured output.');
            }

            $response = 'Fake response for prompt: '.Str::words($prompt->prompt, 10);
        }

        return match (true) {
            is_string($response) => new AgentResponse(
                $invocationId, $response, new Usage, $this->meta()
            ),
            is_array($response) => new StructuredAgentResponse(
                $invocationId, $response, json_encode($response), new Usage, $this->meta()
            ),
            $response instanceof Closure => $this->marshalResponse(
                $response($prompt, $invocationId),
                $invocationId,
                $prompt,
            ),
            default => $response,
        };
    }

    /**
     * Get a new Meta instance for the provider.
     */
    protected function meta(): Meta
    {
        return new Meta($this->providerName(), $this->defaultTextModel());
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
     * Indicate that an exception should be thrown if any prompt is not faked.
     */
    public function preventStrayPrompts(bool $prevent = true): self
    {
        $this->preventStrayPrompts = $prevent;

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
