<?php

namespace Laravel\Ai\Gateway;

use DateInterval;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\StoreGateway;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Responses\Data\StoreFileCounts;
use Laravel\Ai\Store;

class OpenAiStoreGateway implements StoreGateway
{
    use Concerns\HandlesRateLimiting;

    /**
     * Get a vector store by its ID.
     */
    public function getStore(StoreProvider $provider, string $storeId): Store
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->get("vector_stores/{$storeId}")
                ->throw()
        );

        return new Store(
            provider: $provider,
            id: $response->json('id'),
            name: $response->json('name'),
            fileCounts: new StoreFileCounts(
                completed: $response->json('file_counts.completed'),
                pending: $response->json('file_counts.in_progress'),
                failed: $response->json('file_counts.failed'),
            ),
            ready: $response->json('status') === 'completed',
        );
    }

    /**
     * Create a new vector store.
     */
    public function createStore(
        StoreProvider $provider,
        string $name,
        ?string $description = null,
        ?Collection $fileIds = null,
        ?DateInterval $expiresWhenIdleFor = null,
    ): Store {
        $fileIds ??= new Collection;

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->post('vector_stores', array_filter([
                    'name' => $name,
                    'description' => $description,
                    'file_ids' => $fileIds?->values()->all(),
                    'expires_after' => $expiresWhenIdleFor ? [
                        'anchor' => 'last_active_at',
                        'days' => $this->intervalToDays($expiresWhenIdleFor),
                    ] : null,
                ]))
                ->throw()
        );

        return $this->getStore($provider, $response->json('id'));
    }

    /**
     * Add a file to a vector store.
     */
    public function addFile(StoreProvider $provider, string $storeId, string $fileId, array $metadata = []): string
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->post("vector_stores/{$storeId}/files", array_filter([
                    'file_id' => $fileId,
                    'attributes' => ! empty($metadata) ? $metadata : null,
                ]))
                ->throw()
        );

        return $response->json('id');
    }

    /**
     * Remove a file from a vector store.
     */
    public function removeFile(StoreProvider $provider, string $storeId, string $documentId): bool
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->delete("vector_stores/{$storeId}/files/{$documentId}")
                ->throw()
        );

        return $response->json('deleted', false);
    }

    /**
     * Delete a vector store by its ID.
     */
    public function deleteStore(StoreProvider $provider, string $storeId): bool
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->delete("vector_stores/{$storeId}")
                ->throw()
        );

        return $response->json('deleted', false);
    }

    /**
     * Convert a DateInterval to days.
     */
    protected function intervalToDays(DateInterval $interval): int
    {
        return max(1, (int) Carbon::now()->diff(Carbon::now()->add($interval))->days);
    }

    /**
     * Get a configured HTTP client for the given provider.
     */
    protected function client(StoreProvider $provider): PendingRequest
    {
        return Http::withToken($provider->providerCredentials()['key'])
            ->baseUrl('https://api.openai.com/v1');
    }
}
