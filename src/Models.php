<?php

namespace Laravel\Ai;

use Laravel\Ai\Responses\ModelDeleteResponse;
use Laravel\Ai\Responses\ModelListResponse;
use Laravel\Ai\Responses\ModelResponse;

class Models
{
    /**
     * List all available models.
     */
    public static function list(array|string|null $provider = null, array $options = []): ModelListResponse
    {
        $provider = $provider ?? config('ai.default_for_models');

        return Ai::modelProvider($provider)->listModels($options);
    }

    /**
     * Retrieve details of a specific model.
     */
    public static function retrieve(string $modelId, array|string|null $provider = null): ModelResponse
    {
        $provider = $provider ?? config('ai.default_for_models');

        return Ai::modelProvider($provider)->retrieveModel($modelId);
    }

    /**
     * Delete a fine-tuned model.
     */
    public static function delete(string $modelId, array|string|null $provider = null): ModelDeleteResponse
    {
        $provider = $provider ?? config('ai.default_for_models');

        return Ai::modelProvider($provider)->deleteModel($modelId);
    }
}
