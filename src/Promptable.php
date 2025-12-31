<?php

namespace Laravel\Ai;

use Closure;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Laravel\Ai\Contracts\HasMiddleware;
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

trait Promptable
{
    use SerializesModels;

    /**
     * Invoke the agent with a given prompt.
     */
    public function prompt(string $prompt, array $attachments = [], array|string|null $provider = null, ?string $model = null): AgentResponse
    {
        return $this->withModelFailover(
            $this->withinMiddlewarePipeline(function (AgentPrompt $prompt) {
                return $prompt->provider()->prompt(
                    $this, $prompt->prompt, $prompt->attachments->all(), $prompt->model
                );
            }, $prompt, $attachments),
            $provider,
            $model,
        );
    }

    /**
     * Invoke the agent with a given prompt and return a streamable response.
     */
    public function stream(string $prompt, array $attachments = [], ?string $provider = null, ?string $model = null): StreamableAgentResponse
    {
        return $this->withModelFailover(
            $this->withinMiddlewarePipeline(function (AgentPrompt $prompt) {
                return $prompt->provider()->stream(
                    $this, $prompt->prompt, $prompt->attachments->all(), $prompt->model
                );
            }, $prompt, $attachments),
            $provider,
            $model,
        );
    }

    /**
     * Invoke the agent in a queued job.
     */
    public function queue(string $prompt, array $attachments = [], array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        if (static::isFaked()) {
            Ai::recordPrompt(
                new QueuedAgentPrompt($this, $prompt, $attachments, $provider, $model),
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

            $model ??= $provider->defaultTextModel();

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
     * Wrap the given Closure in an agent middleware pipeline.
     */
    private function withinMiddlewarePipeline(Closure $callback, string $prompt, array $attachments): Closure
    {
        $baseMiddleware = static::isFaked() ? [function (AgentPrompt $prompt, Closure $next) {
            Ai::recordPrompt($prompt);

            return $next($prompt);
        }] : [];

        return fn (Provider $provider, string $model) => pipeline()
            ->send(new AgentPrompt(
                $this,
                $prompt,
                $attachments,
                $provider,
                $model
            ))
            ->through(
                $this instanceof HasMiddleware
                    ? [...$baseMiddleware, ...$this->middleware()]
                    : $baseMiddleware
            )
            ->then($callback);
    }

    /**
     * Get the providers and models array given the given initial provider and model values.
     */
    protected function getProvidersAndModels(array|string|null $provider, ?string $model): array
    {
        $provider = is_null($provider) && method_exists($this, 'provider')
            ? $this->provider()
            : $provider;

        if (! is_array($provider) && is_null($model) && method_exists($this, 'model')) {
            $model = $this->model();
        }

        return Provider::formatProviderAndModelList(
            $provider ?? config('ai.default'), $model
        );
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
        Ai::assertAgentWasntPrompted(static::class, $callback);
    }

    /**
     * Assert that a prompt was not received matching a given truth test.
     */
    public static function assertWasntPrompted(Closure|string $callback): void
    {
        Ai::assertAgentWasntPrompted(static::class, $callback);
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
    public static function assertWasntQueued(Closure|string $callback): void
    {
        Ai::assertAgentWasntQueued(static::class, $callback);
    }

    /**
     * Assert that a queued prompt was not received matching a given truth test.
     */
    public static function assertNotQueued(Closure|string $callback): void
    {
        Ai::assertAgentWasntQueued(static::class, $callback);
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
