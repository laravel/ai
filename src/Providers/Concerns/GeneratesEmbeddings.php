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
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\Video;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\EmbeddingsResponse;

trait GeneratesEmbeddings
{
    /**
     * Get embedding vectors representing the given inputs.
     *
     * @param  array<int, string|Audio|Document|Image|Video>  $inputs
     */
    public function embeddings(array $inputs, ?int $dimensions = null, ?string $model = null, int $timeout = 30): EmbeddingsResponse
    {
        if (! is_null($model) && is_null($dimensions)) {
            throw new InvalidArgumentException('Dimensions must be provided when model is specified.');
        }

        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultEmbeddingsModel();
        $dimensions ??= $this->defaultEmbeddingsDimensions();

        $prompt = new EmbeddingsPrompt($inputs, $dimensions, $this, $model, $timeout);

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
        ), fn (EmbeddingsResponse $response) => $this->events->dispatch(new EmbeddingsGenerated(
            $invocationId, $this, $model, $prompt, $response,
        )));
    }

    /**
     * Validate embeddings inputs against the provider's supported media types.
     *
     * @param  array<int, string|Audio|Document|Image|Video>  $inputs
     */
    protected function validateEmbeddingInputs(array $inputs, string $model): void
    {
        $nonTextInputs = array_filter($inputs, fn ($input) => ! is_string($input));

        if ($nonTextInputs === []) {
            return;
        }

        match ($this->driver()) {
            'gemini' => $this->validateGeminiEmbeddingInputs($model),
            'voyageai' => $this->validateVoyageAiEmbeddingInputs($nonTextInputs),
            default => throw new InvalidArgumentException(
                'Provider ['.$this->driver().'] only supports text embeddings inputs.'
            ),
        };
    }

    /**
     * Validate Gemini multimodal embedding inputs.
     */
    protected function validateGeminiEmbeddingInputs(string $model): void
    {
        $model = str_starts_with($model, 'models/') ? substr($model, 7) : $model;

        if ($model !== 'gemini-embedding-2-preview') {
            throw new InvalidArgumentException(
                "Model [{$model}] does not support Gemini multimodal embeddings. Use [gemini-embedding-2-preview]."
            );
        }
    }

    /**
     * Validate Voyage AI multimodal embedding inputs.
     *
     * @param  array<int, Audio|Document|Image|Video>  $inputs
     */
    protected function validateVoyageAiEmbeddingInputs(array $inputs): void
    {
        foreach ($inputs as $input) {
            if ($input instanceof Image && ! $input instanceof ProviderImage) {
                continue;
            }

            throw new InvalidArgumentException(
                'Provider [voyageai] only supports text and image embeddings inputs.'
            );
        }
    }
}
