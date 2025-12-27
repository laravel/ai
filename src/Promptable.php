<?php

namespace Laravel\Ai;

use Closure;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Jobs\BroadcastAgent;
use Laravel\Ai\Jobs\InvokeAgent;
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
            $provider = Ai::textProvider($provider);

            $model ??= $provider->defaultTextModel();

            if (static::isFaked()) {
                $provider = Ai::fakeProviderFor($this, $provider, $model);
            }

            try {
                return $callback($provider, $model);
            } catch (FailoverableException $e) {
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
        return fn (Provider $provider, string $model) => pipeline()
            ->send(new AgentPrompt(
                $this,
                $prompt,
                new Collection($attachments),
                $provider,
                $model
            ))
            ->through($this instanceof HasMiddleware ? $this->middleware() : [])
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
    public static function fake(array $responses): void
    {
        Ai::fakeAgent(static::class, $responses);
    }

    /**
     * Determine if the agent is currently faked.
     */
    public static function isFaked(): bool
    {
        return Ai::hasFakeAgent(static::class);
    }
}
