<?php

namespace Laravel\Ai;

use Laravel\Ai\Responses\BatchListResponse;
use Laravel\Ai\Responses\BatchResponse;

class Batches
{
    /**
     * Create a new batch job.
     */
    public static function create(
        string $inputFileId,
        string $endpoint,
        string $completionWindow = '24h',
        array $options = []
    ): BatchResponse {
        return static::using()->createBatch($inputFileId, $endpoint, $completionWindow, $options);
    }

    /**
     * Retrieve a specific batch.
     */
    public static function retrieve(string $batchId): BatchResponse
    {
        return static::using()->retrieveBatch($batchId);
    }

    /**
     * Cancel an in-progress batch.
     */
    public static function cancel(string $batchId): BatchResponse
    {
        return static::using()->cancelBatch($batchId);
    }

    /**
     * List all batches with optional pagination.
     */
    public static function list(array $options = []): BatchListResponse
    {
        return static::using()->listBatches($options);
    }

    /**
     * Get a batch provider instance by name.
     */
    public static function using(?string $provider = null): Contracts\Providers\BatchProvider
    {
        return Ai::batchProvider($provider);
    }
}
