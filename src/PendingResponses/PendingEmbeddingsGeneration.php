<?php

namespace Laravel\Ai\PendingResponses;

use Laravel\Ai\Ai;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\FakePendingDispatch;
use Laravel\Ai\Jobs\GenerateEmbeddings;
use Laravel\Ai\Prompts\QueuedEmbeddingsPrompt;
use Laravel\Ai\Providers\Provider;
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
            $provider = Ai::embeddingProviderWithFake($provider);

            $model ??= $provider->defaultEmbeddingsModel();

            $dimensions = $this->dimensions ?: $provider->defaultEmbeddingsDimensions();

            try {
                return $provider->embeddings($this->inputs, $dimensions, $model);
            } catch (FailoverableException $e) {
                event(new ProviderFailedOver($provider, $model, $e));

                continue;
            }
        }

        throw $e;
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
}
