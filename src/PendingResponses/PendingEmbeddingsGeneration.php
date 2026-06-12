<?php

namespace Laravel\Ai\PendingResponses;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\FakePendingDispatch;
use Laravel\Ai\Jobs\GenerateEmbeddings;
use Laravel\Ai\Prompts\QueuedEmbeddingsPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\QueuedEmbeddingsResponse;
use Laravel\SerializableClosure\SerializableClosure;

class PendingEmbeddingsGeneration
{
    use Conditionable;

    protected ?int $dimensions = null;

    protected ?int $cacheSeconds = null;

    protected int $timeout = 30;

    /** @var array<string, mixed>|SerializableClosure */
    protected array|SerializableClosure $providerOptions = [];

    /**
     * Create a new pending embeddings generation instance.
     *
     * @param  string[]  $inputs
     *
     * @throws InvalidArgumentException
     */
    public function __construct(protected array $inputs)
    {
        if (! array_is_list($inputs)) {
            throw new InvalidArgumentException('Inputs to embed must be a list, not an associative array.');
        }

        if (blank($inputs)) {
            throw new InvalidArgumentException('At least one input is required to generate embeddings.');
        }

        foreach ($inputs as $index => $input) {
            if (! is_string($input) || blank($input)) {
                throw new InvalidArgumentException("The input at index {$index} must be a non-blank string.");
            }
        }
    }

    /**
     * Specify the dimensions for the embeddings.
     */
    public function dimensions(int $dimensions): self
    {
        $this->dimensions = $dimensions;

        return $this;
    }

    /**
     * Enable caching for this embedding request.
     */
    public function cache(?int $seconds = null): self
    {
        $this->cacheSeconds = $seconds ?? config('ai.caching.embeddings.seconds', 60 * 60 * 24 * 30);

        return $this;
    }

    /**
     * Specify the timeout (in seconds) for the embeddings generation.
     */
    public function timeout(int $seconds = 30): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Specify provider-specific options for embeddings generation.
     *
     * Pass a flat array to apply the same options to every selected provider,
     * or a closure that receives the resolved Provider and returns the options
     * for that provider. Queued closures may only capture serializable values.
     *
     * @param  array<string, mixed>|Closure(Provider): ?array<string, mixed>  $options
     */
    public function providerOptions(array|Closure $options): self
    {
        $this->providerOptions = $options instanceof Closure
            ? new SerializableClosure($options)
            : $options;

        return $this;
    }

    /**
     * Resolve provider options for the given provider.
     *
     * @return array<string, mixed>
     */
    protected function resolveProviderOptions(Provider $provider): array
    {
        if ($this->providerOptions instanceof SerializableClosure) {
            return ($this->providerOptions)($provider) ?: [];
        }

        return $this->providerOptions;
    }

    /**
     * Generate the embeddings.
     *
     * @throws FailoverableException if every configured provider fails to generate the embeddings.
     */
    public function generate(Lab|array|string|null $provider = null, ?string $model = null): EmbeddingsResponse
    {
        $providers = Provider::formatProviderAndModelList(
            $provider ?? config('ai.default_for_embeddings'), $model
        );

        $lastException = null;

        foreach ($providers as $provider => $model) {
            $provider = Ai::fakeableEmbeddingProvider($provider);

            $model ??= $provider->defaultEmbeddingsModel();

            $dimensions = $this->dimensions ?: $provider->defaultEmbeddingsDimensions();

            $providerOptions = $this->resolveProviderOptions($provider);

            if ($cached = $this->generateFromCache($provider, $model, $dimensions, $providerOptions)) {
                return $cached;
            }

            try {
                return tap(
                    $provider->embeddings($this->inputs, $dimensions, $model, $this->timeout, $providerOptions),
                    fn ($response) => $this->cacheEmbeddings($provider, $model, $dimensions, $providerOptions, $response)
                );
            } catch (FailoverableException $e) {
                $lastException = $e;

                event(new ProviderFailedOver($provider, $model, $e));

                continue;
            }
        }

        throw $lastException;
    }

    /**
     * Generate the embeddings from a cached response if possible.
     *
     * @param  array<string, mixed>  $providerOptions
     */
    protected function generateFromCache(Provider $provider, string $model, int $dimensions, array $providerOptions): ?EmbeddingsResponse
    {
        if (! $this->shouldCache()) {
            return null;
        }

        $response = $this->cacheStore()->get($this->cacheKey($provider, $model, $dimensions, $providerOptions));

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
     *
     * @param  array<string, mixed>  $providerOptions
     */
    protected function cacheEmbeddings(Provider $provider, string $model, int $dimensions, array $providerOptions, EmbeddingsResponse $response): void
    {
        if (! $this->shouldCache()) {
            return;
        }

        $this->cacheStore()->put(
            $this->cacheKey($provider, $model, $dimensions, $providerOptions),
            json_encode($response),
            now()->addSeconds($this->cacheSeconds ?? config('ai.caching.embeddings.seconds', 60 * 60 * 24 * 30))
        );
    }

    /**
     * Get the cache key for the given embeddings request.
     *
     * @param  array<string, mixed>  $providerOptions
     */
    protected function cacheKey(Provider $provider, string $model, int $dimensions, array $providerOptions): string
    {
        $optionsFingerprint = $this->fingerprintProviderOptions($providerOptions);

        return 'laravel-embeddings:'.hash(
            'sha256',
            $provider->driver().'-'.$model.'-'.$dimensions.'-'.$optionsFingerprint.'-'.implode('-', $this->inputs),
        );
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     */
    protected function fingerprintProviderOptions(array $providerOptions): string
    {
        if ($providerOptions === []) {
            return '';
        }

        $normalized = $this->normalizeForFingerprint($providerOptions);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * Recursively sort associative keys so the fingerprint is insensitive to key order.
     */
    protected function normalizeForFingerprint(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->normalizeForFingerprint($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->normalizeForFingerprint($item), $value);
    }

    /**
     * Queue the generation of the embeddings.
     */
    public function queue(Lab|array|string|null $provider = null, ?string $model = null): QueuedEmbeddingsResponse
    {
        if (Ai::embeddingsAreFaked()) {
            Ai::recordEmbeddingsGeneration(
                new QueuedEmbeddingsPrompt(
                    $this->inputs,
                    $this->dimensions,
                    $provider,
                    $model,
                    $this->timeout,
                    is_array($this->providerOptions) ? $this->providerOptions : [],
                )
            );

            return new QueuedEmbeddingsResponse(new FakePendingDispatch);
        }

        return new QueuedEmbeddingsResponse(
            GenerateEmbeddings::dispatch($this, $provider, $model),
        );
    }

    /**
     * Get the cache store for embeddings.
     */
    protected function cacheStore(): CacheRepository
    {
        return Cache::store(config('ai.caching.embeddings.store'));
    }

    /**
     * Determine if embeddings should be cached.
     */
    protected function shouldCache(): bool
    {
        return ! is_null($this->cacheSeconds) || config('ai.caching.embeddings.cache', false);
    }
}
