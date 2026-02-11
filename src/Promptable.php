<?php

namespace Laravel\Ai;

use Closure;
use Illuminate\Broadcasting\Channel;
use Illuminate\Container\Container;
use Illuminate\Queue\SerializesModels;
use Laravel\Ai\Attributes\Model as ModelAttribute;
use Laravel\Ai\Attributes\Provider as ProviderAttribute;
use Laravel\Ai\Attributes\Timeout as TimeoutAttribute;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Jobs\BroadcastAgent;
use Laravel\Ai\Jobs\InvokeAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEvent;
use ReflectionClass;

trait Promptable
{
    use SerializesModels;

    /**
     * Create a new instance of the agent.
     */
    public static function make(...$arguments): static
    {
        return match (true) {
            ! empty($arguments) && ! array_is_list($arguments) => Container::getInstance()->makeWith(static::class, $arguments),
            ! empty($arguments) => new static(...$arguments),
            default => Container::getInstance()->make(static::class),
        };
    }

    /**
     * Invoke the agent with a given prompt.
     */
    public function prompt(
        string $prompt,
        array $promptData = [],
        array $attachments = [],
        array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null): AgentResponse
    {
        return $this->withModelFailover(
            fn (Provider $provider, string $model) => $provider->prompt(
                new AgentPrompt($this, $prompt, $promptData, $attachments, $provider, $model, $this->getTimeout($timeout))
            ),
            $provider,
            $model,
        );
    }

    /**
     * Invoke the agent with a given prompt and return a streamable response.
     */
    public function stream(
        string $prompt,
        array $promptData = [],
        array $attachments = [],
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null): StreamableAgentResponse
    {
        return $this->withModelFailover(
            fn (Provider $provider, string $model) => $provider->stream(
                new AgentPrompt($this, $prompt, $promptData, $attachments, $provider, $model, $this->getTimeout($timeout))
            ),
            $provider,
            $model,
        );
    }

    /**
     * Invoke the agent in a queued job.
     */
    public function queue(string $prompt, array $promptData = [], array $attachments = [], array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        if (static::isFaked()) {
            Ai::recordPrompt(
                new QueuedAgentPrompt($this, $prompt, $promptData, $attachments, $provider, $model),
            );

            return new QueuedAgentResponse(new FakePendingDispatch);
        }

        return new QueuedAgentResponse(
            InvokeAgent::dispatch($this, $prompt, $attachments, $provider, $model)
        );
    }

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events.
     */
    public function broadcast(string $prompt, Channel|array $channels, array $attachments = [], bool $now = false, ?string $provider = null, ?string $model = null): StreamableAgentResponse
    {
        return $this->stream($prompt, $attachments, $provider, $model)
            ->each(function (StreamEvent $event) use ($channels, $now) {
                $event->{$now ? 'broadcastNow' : 'broadcast'}($channels);
            });
    }

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events immediately.
     */
    public function broadcastNow(string $prompt, Channel|array $channels, array $attachments = [], ?string $provider = null, ?string $model = null): StreamableAgentResponse
    {
        return $this->broadcast($prompt, $channels, $attachments, now: true, provider: $provider, model: $model);
    }

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events.
     */
    public function broadcastOnQueue(string $prompt, Channel|array $channels, array $attachments = [], ?string $provider = null, ?string $model = null): QueuedAgentResponse
    {
        if (static::isFaked()) {
            Ai::recordPrompt(
                new QueuedAgentPrompt($this, $prompt, $attachments, $provider, $model),
            );

            return new QueuedAgentResponse(new FakePendingDispatch);
        }

        return new QueuedAgentResponse(
            BroadcastAgent::dispatch($this, $prompt, $channels, $attachments, $provider, $model)
        );
    }

    /**
     * Invoke the given Closure with provider / model failover.
     */
    private function withModelFailover(Closure $callback, array|string|null $provider, ?string $model): mixed
    {
        $providers = $this->getProvidersAndModels($provider, $model);

        foreach ($providers as $provider => $model) {
            $provider = Ai::textProviderFor($this, $provider);

            $model ??= $this->getDefaultModelFor($provider);

            try {
                return $callback($provider, $model);
            } catch (FailoverableException $e) {
                event(new AgentFailedOver($this, $provider, $model, $e));

                continue;
            }
        }

        throw $e;
    }

    /**
     * Get the providers and models array given the given initial provider and model values.
     */
    protected function getProvidersAndModels(array|string|null $provider, ?string $model): array
    {
        if (is_null($provider)) {
            if (method_exists($this, 'provider')) {
                $provider = $this->provider();
            } else {
                $attributes = (new ReflectionClass($this))->getAttributes(ProviderAttribute::class);

                $provider = ! empty($attributes) ? $attributes[0]->newInstance()->value : null;
            }
        }

        if (! is_array($provider) && is_null($model)) {
            if (method_exists($this, 'model')) {
                $model = $this->model();
            } else {
                $attributes = (new ReflectionClass($this))->getAttributes(ModelAttribute::class);

                $model = ! empty($attributes) ? $attributes[0]->newInstance()->value : null;
            }
        }

        return Provider::formatProviderAndModelList(
            $provider ?? config('ai.default'), $model
        );
    }

    /**
     * Get the default model to use for the given provider.
     */
    protected function getDefaultModelFor(Provider $provider): string
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

        if (! empty($attributes)) {
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
