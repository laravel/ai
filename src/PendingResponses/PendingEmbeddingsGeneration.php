<?php

namespace Laravel\Ai\PendingResponses;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Files\HasProviderId;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\FakePendingDispatch;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\RemoteAudio;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\RemoteVideo;
use Laravel\Ai\Files\Video;
use Laravel\Ai\Jobs\GenerateEmbeddings;
use Laravel\Ai\PendingResponses\Concerns\ResolvesProviderOptions;
use Laravel\Ai\Prompts\QueuedEmbeddingsPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\QueuedEmbeddingsResponse;

class PendingEmbeddingsGeneration
{
    use Conditionable;
    use ResolvesProviderOptions;

    protected ?int $dimensions = null;

    protected ?int $cacheSeconds = null;

    protected ?bool $shouldCache = null;

    protected int $timeout = 30;

    /**
     * Create a new pending embeddings generation instance.
     *
     * @param  array<int, string|Audio|Document|Image|Video>  $inputs
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
            if (is_string($input)) {
                if (blank($input)) {
                    throw new InvalidArgumentException("The input at index {$index} must be a non-blank string.");
                }

                continue;
            }

            if (! $input instanceof Image
                && ! $input instanceof Audio
                && ! $input instanceof Document
                && ! $input instanceof Video) {
                throw new InvalidArgumentException("The input at index {$index} must be a string or an image, audio, document, or video file.");
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
     * Enable or disable caching for this embedding request.
     */
    public function cache(?int $seconds = null): self
    {
        if (! is_null($seconds) && $seconds <= 0) {
            $this->shouldCache = false;
            $this->cacheSeconds = null;

            return $this;
        }

        $this->shouldCache = true;
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

            if (($cached = $this->generateFromCache($provider, $model, $dimensions, $providerOptions)) instanceof EmbeddingsResponse) {
                return $cached;
            }

            try {
                return tap(
                    $provider->embeddings($this->inputs, $dimensions, $model, $this->timeout, $providerOptions),
                    fn (EmbeddingsResponse $response) => $this->cacheEmbeddings($provider, $model, $dimensions, $providerOptions, $response)
                );
            } catch (FailoverableException $e) {
                $lastException = $e;

                event(new ProviderFailedOver(null, $provider, $model, $e));

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
            $response = json_decode((string) $response, true);

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
        return 'laravel-embeddings:'.hash('sha256', json_encode([
            'driver' => $provider->driver(),
            'model' => $model,
            'dimensions' => $dimensions,
            'options' => $this->fingerprintProviderOptions($providerOptions),
            'inputs' => array_map($this->normalizeInputForCache(...), $this->inputs),
        ], JSON_THROW_ON_ERROR));
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
            return array_map($this->normalizeForFingerprint(...), $value);
        }

        ksort($value);

        return array_map($this->normalizeForFingerprint(...), $value);
    }

    /**
     * Normalize an embeddings input into a deterministic cache representation.
     */
    protected function normalizeInputForCache(mixed $input): array
    {
        if (is_string($input)) {
            return [
                'type' => 'text',
                'value' => $input,
            ];
        }

        $type = match (true) {
            $input instanceof Image => 'image',
            $input instanceof Audio => 'audio',
            $input instanceof Document => 'document',
            $input instanceof Video => 'video',
            default => throw new InvalidArgumentException('Unsupported embeddings input type ['.get_debug_type($input).']'),
        };

        return match (true) {
            $input instanceof HasProviderId => [
                'type' => $type,
                'source' => 'provider',
                'id' => $input->id(),
                'name' => $input->name(),
            ],
            $input instanceof RemoteImage,
            $input instanceof RemoteAudio,
            $input instanceof RemoteDocument,
            $input instanceof RemoteVideo => [
                'type' => $type,
                'source' => 'remote',
                'url' => $input->url,
                'mime' => $input->declaredMimeType(),
                'name' => $input->name(),
            ],
            $input instanceof StorableFile => [
                'type' => $type,
                'source' => 'content',
                'hash' => hash('sha256', $input->content()),
                'mime' => $input->mimeType(),
                'name' => $input->name(),
            ],
            default => throw new InvalidArgumentException('Unsupported embeddings input type ['.get_debug_type($input).']'),
        };
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
        if (! is_null($this->shouldCache)) {
            return $this->shouldCache;
        }

        return (bool) config('ai.caching.embeddings.cache', false);
    }
}
