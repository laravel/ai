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
        if (is_string($input)) {
            return ['text' => $input];
        }

        $block = $this->mapAttachment($input);

        // The embeddings endpoint predates the Interactions API and still takes content parts...
        return isset($block['uri'])
            ? ['fileData' => array_filter([
                'mimeType' => $block['mime_type'] ?? null,
                'fileUri' => $block['uri'],
            ])]
            : ['inlineData' => array_filter([
                'mimeType' => $block['mime_type'] ?? null,
                'data' => $block['data'] ?? null,
            ])];
    }
}
