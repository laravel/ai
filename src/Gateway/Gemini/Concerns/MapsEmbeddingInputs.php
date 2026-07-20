<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

trait MapsEmbeddingInputs
{
    /**
     * Normalize model names accepted by the Gemini API.
     */
    protected function normalizeEmbeddingModel(string $model): string
    {
        return str_starts_with($model, 'models/') ? substr($model, 7) : $model;
    }

    /**
     * Map a Laravel embeddings input to a Gemini content part.
     */
    protected function mapEmbeddingInput(mixed $input): array
    {
        return is_string($input) ? ['text' => $input] : $this->mapAttachment($input);
    }
}
