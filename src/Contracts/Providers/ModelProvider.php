<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Gateway\ModelGateway;
use Laravel\Ai\Responses\ModelDeleteResponse;
use Laravel\Ai\Responses\ModelListResponse;
use Laravel\Ai\Responses\ModelResponse;

interface ModelProvider
{
    /**
     * List all available models.
     */
    public function listModels(array $options = []): ModelListResponse;

    /**
     * Retrieve details of a specific model.
     */
    public function retrieveModel(string $modelId): ModelResponse;

    /**
     * Delete a fine-tuned model.
     */
    public function deleteModel(string $modelId): ModelDeleteResponse;

    /**
     * Get the provider's model gateway.
     */
    public function modelGateway(): ModelGateway;

    /**
     * Set the provider's model gateway.
     */
    public function useModelGateway(ModelGateway $gateway): self;
}
