<?php

namespace Laravel\Ai\Contracts\Gateway;

use Laravel\Ai\Contracts\Providers\BatchProvider;
use Laravel\Ai\Responses\BatchListResponse;
use Laravel\Ai\Responses\BatchResponse;

interface BatchGateway
{
    /**
     * Create a new batch job.
     */
    public function createBatch(
        BatchProvider $provider,
        string $inputFileId,
        string $endpoint,
        string $completionWindow = '24h',
        array $options = []
    ): BatchResponse;

    /**
     * Retrieve a specific batch.
     */
    public function retrieveBatch(BatchProvider $provider, string $batchId): BatchResponse;

    /**
     * Cancel an in-progress batch.
     */
    public function cancelBatch(BatchProvider $provider, string $batchId): BatchResponse;

    /**
     * List all batches with optional pagination.
     */
    public function listBatches(BatchProvider $provider, array $options = []): BatchListResponse;
}
