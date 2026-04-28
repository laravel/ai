<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\Video;
use Laravel\Ai\Gateway\VoyageAi\VoyageAiGateway;

class VoyageAiProvider extends Provider implements EmbeddingProvider, RerankingProvider
{
    use Concerns\GeneratesEmbeddings;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasRerankingGateway;
    use Concerns\Reranks;

    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {}

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'voyage-4';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 1024;
    }

    /**
     * Validate embeddings inputs against Voyage AI's supported media types.
     */
    protected function validateEmbeddingInputs(array $inputs, string $model): void
    {
        foreach ($inputs as $input) {
            if (is_string($input)) {
                continue;
            }

            if ($input instanceof Image && ! $input instanceof ProviderImage) {
                if ($this->isVoyageMultimodalModel($model)) {
                    continue;
                }

                throw new InvalidArgumentException(
                    "Model [{$model}] does not support Voyage AI image embeddings. Use [voyage-multimodal-3.5] or [voyage-multimodal-3]."
                );
            }

            if ($input instanceof Video) {
                if ($model === 'voyage-multimodal-3.5') {
                    continue;
                }

                throw new InvalidArgumentException(
                    "Model [{$model}] does not support Voyage AI video embeddings. Use [voyage-multimodal-3.5]."
                );
            }

            throw new InvalidArgumentException(
                'Provider [voyageai] only supports text, image, and video embeddings inputs.'
            );
        }
    }

    /**
     * Determine if the given model supports Voyage AI multimodal embeddings.
     */
    protected function isVoyageMultimodalModel(string $model): bool
    {
        return in_array($model, ['voyage-multimodal-3.5', 'voyage-multimodal-3'], true);
    }

    /**
     * Get the provider's embedding gateway.
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= new VoyageAiGateway;
    }

    /**
     * Get the name of the default reranking model.
     */
    public function defaultRerankingModel(): string
    {
        return $this->config['models']['reranking']['default'] ?? 'rerank-2.5-lite';
    }

    /**
     * Get the provider's reranking gateway.
     */
    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway ??= new VoyageAiGateway;
    }
}
