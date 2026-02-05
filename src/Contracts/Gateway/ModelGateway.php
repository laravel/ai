<?php

namespace Laravel\Ai\Contracts\Gateway;

use Laravel\Ai\Contracts\Providers\ModelProvider;
use Laravel\Ai\Responses\ModelDeleteResponse;
use Laravel\Ai\Responses\ModelListResponse;
use Laravel\Ai\Responses\ModelResponse;

interface ModelGateway
{
    /**
     * List all available models.
     */
    public function listModels(ModelProvider $provider, array $options = []): ModelListResponse;

    /**
     * Retrieve details of a specific model.
     */
    public function retrieveModel(ModelProvider $provider, string $modelId): ModelResponse;

    /**
     * Delete a fine-tuned model.
     */
    public function deleteModel(ModelProvider $provider, string $modelId): ModelDeleteResponse;
}
