<?php

namespace Laravel\Ai\PendingResponses;

use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\FakePendingDispatch;
use Laravel\Ai\Jobs\GenerateEmbeddings;
use Laravel\Ai\Prompts\QueuedEmbeddingsPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\QueuedEmbeddingsResponse;

class PendingEmbeddingsGeneration
{
    protected ?int $dimensions = null;

    public function __construct(
        protected array $inputs,
    ) {}

    /**
     * Specify the dimensions for the embeddings.
     */
    public function dimensions(int $dimensions): self
    {
        $this->dimensions = $dimensions;

        return $this;
    }

    /**
     * Generate the embeddings.
     */
    public function generate(array|string|null $provider = null, ?string $model = null): EmbeddingsResponse
    {
        $providers = Provider::formatProviderAndModelList(
            $provider ?? config('ai.default_for_embeddings'), $model
        );

        foreach ($providers as $provider => $model) {
            $provider = Ai::fakeableEmbeddingProvider($provider);

            $model ??= $provider->defaultEmbeddingsModel();

            $dimensions = $this->dimensions ?: $provider->defaultEmbeddingsDimensions();

            if ($cached = $this->generateFromCache($provider, $model, $dimensions)) {
                return $cached;
            }

            try {
                return tap($provider->embeddings($this->inputs, $dimensions, $model), fn () =>
                    $this->cacheEmbeddings($provider, $model, $dimensions, $response)
                );
            } catch (FailoverableException $e) {
                event(new ProviderFailedOver($provider, $model, $e));

                continue;
            }
        }

        throw $e;
    }

    /**
     * Generate the embeddings from a cached response if possible.
     */
    protected function generateFromCache(Provider $provider, string $model, int $dimensions): ?EmbeddingsResponse
    {
        if (! $this->shouldCache()) {
            return null;
        }

        $key = 'laravel-embeddings:'.hash('sha256', $provider->driver().'-'.$model.'-'.$dimensions.'-'.implode('-', $this->inputs));

        $response = Cache::get($key);

        if (! is_null($response)) {
            $response = json_decode($response, true);

            return new EmbeddingsResponse($response['embeddings'], 0, new Meta(
                provider: $response['meta']['provider'],
                model: $response['meta']['model'],
            ));
        }

        return null;
    }

    /**
     * Cache the given embeddings response.
     */
    protected function cacheEmbeddings(Provider $provider, string $model, int $dimensions, EmbeddingsResponse $response): void
    {
        if (! $this->shouldCache()) {
            return;
        }

        $key = 'laravel-embeddings:'.hash('sha256', $provider->driver().'-'.$model.'-'.$dimensions.'-'.implode('-', $this->inputs));

        Cache::put($key, json_encode($response), now()->addDays(30));
    }

    /**
     * Queue the generation of the embeddings.
     */
    public function queue(array|string|null $provider = null, ?string $model = null): QueuedEmbeddingsResponse
    {
        if (Ai::embeddingsAreFaked()) {
            Ai::recordEmbeddingsGeneration(
                new QueuedEmbeddingsPrompt(
                    $this->inputs,
                    $this->dimensions,
                    $provider,
                    $model
                )
            );

            return new QueuedEmbeddingsResponse(new FakePendingDispatch);
        }

        return new QueuedEmbeddingsResponse(
            GenerateEmbeddings::dispatch($this, $provider, $model),
        );
    }

    /**
     * Determine if embeddings should be cached.
     */
    protected function shouldCache(): bool
    {
        return config('ai.caching.embeddings.cache', false);
    }
}
