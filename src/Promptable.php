<?php

namespace Laravel\Ai;

use Closure;
use Generator;
use Illuminate\Broadcasting\Channel;
use Illuminate\Container\Container;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Attributes\Model as ModelAttribute;
use Laravel\Ai\Attributes\Provider as ProviderAttribute;
use Laravel\Ai\Attributes\Timeout as TimeoutAttribute;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Attributes\WithoutBroadcasting;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Jobs\BroadcastAgent;
use Laravel\Ai\Jobs\InvokeAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEvent;
use ReflectionClass;
use RuntimeException;

trait Promptable
{
    use SerializesModels;

    /**
     * Create a new instance of the agent.
     */
    public static function make(...$arguments): static
    {
        return match (true) {
            $arguments !== [] && ! array_is_list($arguments) => Container::getInstance()->makeWith(static::class, $arguments),
            $arguments !== [] => new static(...$arguments),
            default => Container::getInstance()->make(static::class),
        };
    }

    /**
     * Invoke the agent with a given prompt, or resume a paused run with tool approval decisions.
     *
     * @param  array<string, Decision|bool>|string  $prompt
     */
    public function prompt(
        array|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null): AgentResponse
    {
        [$text, $resume] = $this->extractResume($prompt);

        $run = fn (TextProvider $provider, string $model): AgentResponse => $provider->prompt(
            new AgentPrompt($this, $text, $attachments, $provider, $model, $this->getTimeout($timeout), resume: $resume)
        );

        if ($resume !== null) {
            [$provider, $model] = $this->iterateProvidersWithFailover(
                $this->providersForResume($provider, $model)
            )->current();

            return $run($provider, $model);
        }

        return $this->withModelFailover($run, $provider, $model);
    }

    /**
     * Invoke the agent with a given prompt and return a streamable response.
     *
     * @param  array<string, Decision|bool>|string  $prompt
     */
    public function stream(
        array|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null): StreamableAgentResponse
    {
        [$text, $resume] = $this->extractResume($prompt);

        return $this->streamPrompt($text, $resume, $attachments, $provider, $model, $timeout);
    }

    /**
     * Stream a text prompt or an approval resume through the configured providers.
     */
    private function streamPrompt(
        string $prompt,
        ?array $resume,
        array $attachments,
        Lab|array|string|null $provider,
        ?string $model,
        ?int $timeout): StreamableAgentResponse
    {
        $providers = $resume !== null
            ? $this->providersForResume($provider, $model)
            : $this->getProvidersAndModelsForFailover($provider, $model);
        $resolvedTimeout = $this->getTimeout($timeout);

        $invocationId = (string) Str::uuid7();

        if (count($providers) === 1) {
            [$resolved, $resolvedModel] = $this->iterateProvidersWithFailover($providers)->current();

            return $resolved->stream(
                new AgentPrompt($this, $prompt, $attachments, $resolved, $resolvedModel, $resolvedTimeout, $invocationId, $resume)
            );
        }

        $meta = new Meta;
        $outer = null;

        $outer = new StreamableAgentResponse(
            $invocationId,
            function () use ($providers, $prompt, $resume, $attachments, $resolvedTimeout, $invocationId, &$outer) {
                $lastException = null;

                foreach ($this->iterateProvidersWithFailover($providers) as [$provider, $model]) {
                    $started = false;

                    try {
                        $innerResponse = $provider->stream(
                            new AgentPrompt($this, $prompt, $attachments, $provider, $model, $resolvedTimeout, $invocationId, $resume)
                        );

                        $innerResponse->then(fn (StreamedAgentResponse $response): StreamableAgentResponse => $outer->adoptStateFrom($response));

                        foreach ($innerResponse as $event) {
                            $started = true;

                            yield $event;
                        }

                        return;
                    } catch (FailoverableException $e) {
                        if ($started) {
                            throw $e;
                        }

                        $lastException = $this->recordAgentFailover($provider, $model, $e);
                    }
                }

                throw $lastException;
            },
            $meta,
        );

        return $outer;
    }

    /**
     * Invoke the agent in a queued job.
     *
     * @param  array<string, Decision|bool>|string  $prompt
     */
    public function queue(array|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        [$text, $resume] = $this->extractResume($prompt);

        if (static::isFaked()) {
            Ai::recordPrompt(
                new QueuedAgentPrompt($this, $text, $attachments, $provider, $model, $resume),
            );

            return new QueuedAgentResponse(new FakePendingDispatch);
        }

        return new QueuedAgentResponse(
            InvokeAgent::dispatch($this, $text, $attachments, $provider, $model, $resume)
        );
    }

    /**
     * Split a prompt into its text and tool approval resume parts.
     *
     * @param  array<string, Decision|bool>|string  $prompt
     * @return array{string, ?array<string, Decision>}
     */
    private function extractResume(array|string $prompt): array
    {
        if (is_string($prompt)) {
            return [$prompt, null];
        }

        return ['', Decision::normalize($prompt)];
    }

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events.
     *
     * @param  array<string, Decision|bool>|string  $prompt
     */
    public function broadcast(array|string $prompt, Channel|array $channels, array $attachments = [], bool $now = false, Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        $without = WithoutBroadcasting::eventsFor($this);

        return $this->stream($prompt, $attachments, $provider, $model)
            ->each(function (StreamEvent $event) use ($channels, $now, $without): void {
                if (WithoutBroadcasting::excludes($without, $event)) {
                    return;
                }

                $event->{$now ? 'broadcastNow' : 'broadcast'}($channels);
            });
    }

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events immediately.
     *
     * @param  array<string, Decision|bool>|string  $prompt
     */
    public function broadcastNow(array|string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        return $this->broadcast($prompt, $channels, $attachments, now: true, provider: $provider, model: $model);
    }

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events.
     *
     * @param  array<string, Decision|bool>|string  $prompt
     */
    public function broadcastOnQueue(array|string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        [$text, $resume] = $this->extractResume($prompt);

        if (static::isFaked()) {
            Ai::recordPrompt(
                new QueuedAgentPrompt($this, $text, $attachments, $provider, $model, $resume),
            );

            return new QueuedAgentResponse(new FakePendingDispatch);
        }

        return new QueuedAgentResponse(
            BroadcastAgent::dispatch($this, $text, $channels, $attachments, $provider, $model, $resume)
        );
    }

    /**
     * Invoke the given Closure with provider / model failover.
     */
    private function withModelFailover(Closure $callback, Lab|array|string|null $provider, ?string $model): mixed
    {
        $lastException = null;

        foreach ($this->iterateProvidersWithFailover($this->getProvidersAndModelsForFailover($provider, $model)) as [$provider, $model]) {
            try {
                return $callback($provider, $model);
            } catch (FailoverableException $e) {
                $lastException = $this->recordAgentFailover($provider, $model, $e);
            }
        }

        throw $lastException;
    }

    /**
     * Get the configured providers and models for failover.
     */
    private function getProvidersAndModelsForFailover(Lab|array|string|null $provider, ?string $model): array
    {
        $providers = $this->getProvidersAndModels($provider, $model);

        if (empty($providers)) {
            throw new RuntimeException('No AI providers were configured.');
        }

        return $providers;
    }

    /**
     * Get the single provider / model pair a resume must run against, since a resume may not fail over to a different provider.
     */
    private function providersForResume(Lab|array|string|null $provider, ?string $model): array
    {
        return array_slice($this->getProvidersAndModelsForFailover($provider, $model), 0, 1, true);
    }

    /**
     * Iterate the configured provider / model pairs.
     *
     * @param  array<string, string|null>  $providers
     * @return Generator<int, array{TextProvider, string}>
     */
    private function iterateProvidersWithFailover(array $providers): Generator
    {
        foreach ($providers as $provider => $model) {
            $provider = Ai::textProviderFor($this, $provider);

            yield [$provider, $model ?? $this->getDefaultModelFor($provider)];
        }
    }

    /**
     * Record that an agent failed over to the next configured provider.
     */
    private function recordAgentFailover(Provider $provider, string $model, FailoverableException $exception): FailoverableException
    {
        event(new AgentFailedOver($this, $provider, $model, $exception));

        return $exception;
    }

    /**
     * Get the providers and models array for the given initial provider and model values.
     */
    protected function getProvidersAndModels(Lab|array|string|null $provider, ?string $model): array
    {
        if (is_null($provider)) {
            if (method_exists($this, 'provider')) {
                $provider = $this->provider();
            } else {
                $attributes = (new ReflectionClass($this))->getAttributes(ProviderAttribute::class);

                $provider = $attributes === [] ? null : $attributes[0]->newInstance()->value;
            }
        }

        if (! is_array($provider) && is_null($model)) {
            if (method_exists($this, 'model')) {
                $model = $this->model();
            } else {
                $attributes = (new ReflectionClass($this))->getAttributes(ModelAttribute::class);

                $model = $attributes === [] ? null : $attributes[0]->newInstance()->value;
            }
        }

        $resolved = $provider ?? config('ai.default');

        if (is_array($resolved) && array_intersect(array_keys($resolved), ['text', 'image', 'audio', 'transcription', 'embedding', 'reranking'])) {
            throw new InvalidArgumentException('The "ai.default" config value must be a string provider name or a Lab enum, not an array.');
        }

        return Provider::formatProviderAndModelList($resolved, $model);
    }

    /**
     * Get the default model to use for the given provider.
     */
    protected function getDefaultModelFor(TextProvider $provider): string
    {
        $reflection = new ReflectionClass($this);

        if (! empty($reflection->getAttributes(UseSmartestModel::class))) {
            return $provider->smartestTextModel();
        }

        if (! empty($reflection->getAttributes(UseCheapestModel::class))) {
            return $provider->cheapestTextModel();
        }

        return $provider->defaultTextModel();
    }

    /**
     * Get the timeout to use for the agent prompt.
     */
    protected function getTimeout(?int $timeout): int
    {
        if (! is_null($timeout)) {
            return $timeout;
        }

        if (method_exists($this, 'timeout')) {
            return $this->timeout();
        }

        $attributes = (new ReflectionClass($this))->getAttributes(TimeoutAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->value;
        }

        return 60;
    }

    /**
     * Fake the responses returned by the agent.
     */
    public static function fake(Closure|array $responses = []): FakeTextGateway
    {
        return Ai::fakeAgent(static::class, $responses);
    }

    /**
     * Assert that a prompt was received matching a given truth test.
     */
    public static function assertPrompted(Closure|string $callback): void
    {
        Ai::assertAgentWasPrompted(static::class, $callback);
    }

    /**
     * Assert that a prompt was not received matching a given truth test.
     */
    public static function assertNotPrompted(Closure|string $callback): void
    {
        Ai::assertAgentNotPrompted(static::class, $callback);
    }

    /**
     * Assert that no prompts were received.
     */
    public static function assertNeverPrompted(): void
    {
        Ai::assertAgentNeverPrompted(static::class);
    }

    /**
     * Assert that a queued prompt was received matching a given truth test.
     */
    public static function assertQueued(Closure|string $callback): void
    {
        Ai::assertAgentWasQueued(static::class, $callback);
    }

    /**
     * Assert that a queued prompt was not received matching a given truth test.
     */
    public static function assertNotQueued(Closure|string $callback): void
    {
        Ai::assertAgentNotQueued(static::class, $callback);
    }

    /**
     * Assert that no queued prompts were received.
     */
    public static function assertNeverQueued(): void
    {
        Ai::assertAgentNeverQueued(static::class);
    }

    /**
     * Determine if the agent is currently faked.
     */
    public static function isFaked(): bool
    {
        return Ai::hasFakeGatewayFor(static::class);
    }
}
