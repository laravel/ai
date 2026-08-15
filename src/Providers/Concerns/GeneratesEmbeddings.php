<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Video;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\EmbeddingsResponse;

trait GeneratesEmbeddings
{
    /**
     * Get embedding vectors representing the given inputs.
     *
     * @param  array<int, string|Audio|Document|Image|Video>  $inputs
     * @param  array<string, mixed>  $providerOptions
     */
    public function embeddings(array $inputs, ?int $dimensions = null, ?string $model = null, int $timeout = 30, array $providerOptions = []): EmbeddingsResponse
    {
        if (! is_null($model) && is_null($dimensions)) {
            if (! $this->supportsNativeEmbeddingDimensions()) {
                throw new InvalidArgumentException('Dimensions must be provided when model is specified.');
            }

            $dimensions = 0;
        }

        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultEmbeddingsModel();
        $dimensions ??= $this->defaultEmbeddingsDimensions();

        $prompt = new EmbeddingsPrompt($inputs, $dimensions, $this, $model, $timeout, $providerOptions);

        if (Ai::embeddingsAreFaked()) {
            Ai::recordEmbeddingsGeneration($prompt);
        } else {
            $this->validateEmbeddingInputs($inputs, $model);
        }

        $this->events->dispatch(new GeneratingEmbeddings(
            $invocationId, $this, $model, $prompt,
        ));

        return tap($this->embeddingGateway()->generateEmbeddings(
            $this,
            $model,
            $inputs,
            $dimensions,
            $timeout,
            $providerOptions,
        ), fn (EmbeddingsResponse $response) => $this->events->dispatch(new EmbeddingsGenerated(
            $invocationId, $this, $model, $prompt, $response,
        )));
    }

    /**
     * Determine if the provider may omit dimensions and use a model's native embedding dimensions.
     */
    protected function supportsNativeEmbeddingDimensions(): bool
    {
        return false;
    }

    /**
     * Validate embeddings inputs against the provider's supported media types.
     *
     * @param  array<int, string|Audio|Document|Image|Video>  $inputs
     */
    protected function validateEmbeddingInputs(array $inputs, string $model): void
    {
        foreach ($inputs as $input) {
            if (is_string($input)) {
                continue;
            }

            throw new InvalidArgumentException(
                'Provider ['.$this->driver().'] only supports text embeddings inputs.'
            );
        }
    }
}
