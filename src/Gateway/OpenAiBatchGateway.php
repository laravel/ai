<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\BatchGateway;
use Laravel\Ai\Contracts\Providers\BatchProvider;
use Laravel\Ai\Responses\BatchListResponse;
use Laravel\Ai\Responses\BatchResponse;
use Laravel\Ai\Responses\Data\BatchError;
use Laravel\Ai\Responses\Data\BatchRequestCounts;
use Laravel\Ai\Responses\Data\Meta;

class OpenAiBatchGateway implements BatchGateway
{
    use Concerns\HandlesRateLimiting;

    /**
     * Create a new batch job.
     */
    public function createBatch(
        BatchProvider $provider,
        string $inputFileId,
        string $endpoint,
        string $completionWindow = '24h',
        array $options = []
    ): BatchResponse {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->post('https://api.openai.com/v1/batches', array_filter([
                    'input_file_id' => $inputFileId,
                    'endpoint' => $endpoint,
                    'completion_window' => $completionWindow,
                    'metadata' => $options['metadata'] ?? null,
                ]))
                ->throw()
        );

        return $this->toBatchResponse($response->json(), $provider);
    }

    /**
     * Retrieve a specific batch.
     */
    public function retrieveBatch(BatchProvider $provider, string $batchId): BatchResponse
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->get("https://api.openai.com/v1/batches/{$batchId}")
                ->throw()
        );

        return $this->toBatchResponse($response->json(), $provider);
    }

    /**
     * Cancel an in-progress batch.
     */
    public function cancelBatch(BatchProvider $provider, string $batchId): BatchResponse
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->post("https://api.openai.com/v1/batches/{$batchId}/cancel")
                ->throw()
        );

        return $this->toBatchResponse($response->json(), $provider);
    }

    /**
     * List all batches with optional pagination.
     */
    public function listBatches(BatchProvider $provider, array $options = []): BatchListResponse
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->get('https://api.openai.com/v1/batches', array_filter([
                    'after' => $options['after'] ?? null,
                    'limit' => $options['limit'] ?? null,
                ]))
                ->throw()
        );

        $data = $response->json();

        $batches = array_map(
            fn ($batchData) => $this->toBatchResponse($batchData, $provider),
            $data['data'] ?? []
        );

        return new BatchListResponse(
            batches: $batches,
            hasMore: $data['has_more'] ?? false,
            firstId: $data['first_id'] ?? null,
            lastId: $data['last_id'] ?? null,
        );
    }

    /**
     * Convert raw API response to BatchResponse.
     */
    protected function toBatchResponse(array $data, BatchProvider $provider): BatchResponse
    {
        $requestCounts = new BatchRequestCounts(
            total: $data['request_counts']['total'] ?? 0,
            completed: $data['request_counts']['completed'] ?? 0,
            failed: $data['request_counts']['failed'] ?? 0,
        );

        $errors = array_map(
            fn ($errorData) => new BatchError(
                code: $errorData['code'] ?? '',
                message: $errorData['message'] ?? '',
                param: $errorData['param'] ?? null,
                line: $errorData['line'] ?? null,
            ),
            $data['errors']['data'] ?? []
        );

        return new BatchResponse(
            id: $data['id'],
            object: $data['object'],
            endpoint: $data['endpoint'],
            inputFileId: $data['input_file_id'],
            outputFileId: $data['output_file_id'] ?? null,
            errorFileId: $data['error_file_id'] ?? null,
            status: $data['status'],
            completionWindow: $data['completion_window'],
            createdAt: $data['created_at'],
            completedAt: $data['completed_at'] ?? null,
            failedAt: $data['failed_at'] ?? null,
            cancelledAt: $data['cancelled_at'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            requestCounts: $requestCounts,
            errors: $errors,
            metadata: $data['metadata'] ?? [],
            meta: new Meta($provider->name()),
        );
    }
}
