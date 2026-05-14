<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\EmbeddingsResponse;

trait GeneratesEmbeddings
{
    /**
     * Get embedding vectors representing the given inputs.
     *
     * @param  string[]  $inputs
     * @param  array<string, mixed>  $providerOptions
     */
    public function embeddings(array $inputs, ?int $dimensions = null, ?string $model = null, int $timeout = 30, array $providerOptions = []): EmbeddingsResponse
    {
        if (! is_null($model) && is_null($dimensions)) {
            throw new InvalidArgumentException('Dimensions must be provided when model is specified.');
        }

        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultEmbeddingsModel();
        $dimensions ??= $this->defaultEmbeddingsDimensions();

        $prompt = new EmbeddingsPrompt($inputs, $dimensions, $this, $model, $timeout, $providerOptions);

        if (Ai::embeddingsAreFaked()) {
            Ai::recordEmbeddingsGeneration($prompt);
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
}
