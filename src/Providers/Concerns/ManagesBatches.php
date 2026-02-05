<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Responses\BatchListResponse;
use Laravel\Ai\Responses\BatchResponse;

trait ManagesBatches
{
    /**
     * Create a new batch job.
     */
    public function createBatch(
        string $inputFileId,
        string $endpoint,
        string $completionWindow = '24h',
        array $options = []
    ): BatchResponse {
        return $this->batchGateway()->createBatch(
            $this,
            $inputFileId,
            $endpoint,
            $completionWindow,
            $options
        );
    }

    /**
     * Retrieve a specific batch.
     */
    public function retrieveBatch(string $batchId): BatchResponse
    {
        return $this->batchGateway()->retrieveBatch($this, $batchId);
    }

    /**
     * Cancel an in-progress batch.
     */
    public function cancelBatch(string $batchId): BatchResponse
    {
        return $this->batchGateway()->cancelBatch($this, $batchId);
    }

    /**
     * List all batches with optional pagination.
     */
    public function listBatches(array $options = []): BatchListResponse
    {
        return $this->batchGateway()->listBatches($this, $options);
    }
}
