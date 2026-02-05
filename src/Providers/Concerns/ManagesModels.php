<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Responses\ModelDeleteResponse;
use Laravel\Ai\Responses\ModelListResponse;
use Laravel\Ai\Responses\ModelResponse;

trait ManagesModels
{
    /**
     * List all available models.
     */
    public function listModels(array $options = []): ModelListResponse
    {
        return $this->modelGateway()->listModels($this, $options);
    }

    /**
     * Retrieve details of a specific model.
     */
    public function retrieveModel(string $modelId): ModelResponse
    {
        return $this->modelGateway()->retrieveModel($this, $modelId);
    }

    /**
     * Delete a fine-tuned model.
     */
    public function deleteModel(string $modelId): ModelDeleteResponse
    {
        return $this->modelGateway()->deleteModel($this, $modelId);
    }
}
