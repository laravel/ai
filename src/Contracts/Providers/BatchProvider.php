<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Gateway\BatchGateway;
use Laravel\Ai\Responses\BatchListResponse;
use Laravel\Ai\Responses\BatchResponse;

interface BatchProvider
{
    /**
     * Create a new batch job.
     */
    public function createBatch(
        string $inputFileId,
        string $endpoint,
        string $completionWindow = '24h',
        array $options = []
    ): BatchResponse;

    /**
     * Retrieve a specific batch.
     */
    public function retrieveBatch(string $batchId): BatchResponse;

    /**
     * Cancel an in-progress batch.
     */
    public function cancelBatch(string $batchId): BatchResponse;

    /**
     * List all batches with optional pagination.
     */
    public function listBatches(array $options = []): BatchListResponse;

    /**
     * Get the provider's batch gateway.
     */
    public function batchGateway(): BatchGateway;

    /**
     * Set the provider's batch gateway.
     */
    public function useBatchGateway(BatchGateway $gateway): self;
}
