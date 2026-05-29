<?php

namespace Laravel\Ai\Gateway\Oracle\Concerns;

trait DetectsModelFamily
{
    /**
     * Determine whether the given model uses the Cohere chat request/response format.
     *
     * OCI exposes two API formats: COHERE for the Cohere model family and GENERIC for
     * everything else (Meta Llama, xAI Grok, etc.). Detection is by model id prefix.
     */
    protected function isCohereModel(string $model): bool
    {
        return str_starts_with($model, 'cohere.');
    }

    /**
     * Get the OCI chat apiFormat discriminator for the given model.
     */
    protected function apiFormat(string $model): string
    {
        return $this->isCohereModel($model) ? 'COHERE' : 'GENERIC';
    }

    /**
     * Determine whether the embedding model accepts a configurable output dimension.
     *
     * Cohere embed v3 models emit a fixed-size vector; only the v4 family supports the
     * outputDimensions field, so the requested dimension is forwarded for it alone.
     */
    protected function supportsOutputDimensions(string $model): bool
    {
        return str_contains($model, 'embed-v4') || str_contains($model, 'embed-4');
    }
}
