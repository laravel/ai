<?php

namespace Laravel\Ai\Gateway;

use DateInterval;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\StoreGateway;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\StoreFileCounts;
use Laravel\Ai\Store;

class OpenAiStoreGateway implements StoreGateway
{
    /**
     * Get a vector store by its ID.
     */
    public function getStore(StoreProvider $provider, string $storeId): Store
    {
        try {
            $response = Http::withToken($provider->providerCredentials()['key'])
                ->get("https://api.openai.com/v1/vector_stores/{$storeId}")
                ->throw();
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw RateLimitedException::forProvider(
                    $provider->name(), $e->getCode(), $e
                );
            }

            throw $e;
        }

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
        try {
            $fileIds ??= new Collection;

            $response = Http::withToken($provider->providerCredentials()['key'])
                ->post('https://api.openai.com/v1/vector_stores', array_filter([
                    'name' => $name,
                    'description' => $description,
                    'file_ids' => $fileIds?->values()->all(),
                    'expires_after' => $expiresWhenIdleFor ? [
                        'anchor' => 'last_active_at',
                        'days' => $this->intervalToDays($expiresWhenIdleFor),
                    ] : null,
                ]))
                ->throw();
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw RateLimitedException::forProvider(
                    $provider->name(), $e->getCode(), $e
                );
            }

            throw $e;
        }

        return $this->getStore($provider, $response->json('id'));
    }

    /**
     * Add a file to a vector store.
     */
    public function addFile(StoreProvider $provider, string $storeId, string $fileId): string
    {
        try {
            $response = Http::withToken($provider->providerCredentials()['key'])
                ->post("https://api.openai.com/v1/vector_stores/{$storeId}/files", [
                    'file_id' => $fileId,
                ])
                ->throw();
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw RateLimitedException::forProvider(
                    $provider->name(), $e->getCode(), $e
                );
            }

            throw $e;
        }

        return $response->json('id');
    }

    /**
     * Remove a file from a vector store.
     */
    public function removeFile(StoreProvider $provider, string $storeId, string $documentId): bool
    {
        try {
            $response = Http::withToken($provider->providerCredentials()['key'])
                ->delete("https://api.openai.com/v1/vector_stores/{$storeId}/files/{$documentId}")
                ->throw();
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw RateLimitedException::forProvider(
                    $provider->name(), $e->getCode(), $e
                );
            }

            throw $e;
        }

        return $response->json('deleted', false);
    }

    /**
     * Delete a vector store by its ID.
     */
    public function deleteStore(StoreProvider $provider, string $storeId): bool
    {
        try {
            $response = Http::withToken($provider->providerCredentials()['key'])
                ->delete("https://api.openai.com/v1/vector_stores/{$storeId}")
                ->throw();
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw RateLimitedException::forProvider(
                    $provider->name(), $e->getCode(), $e
                );
            }

            throw $e;
        }

        return $response->json('deleted', false);
    }

    /**
     * Convert a DateInterval to days.
     */
    protected function intervalToDays(DateInterval $interval): int
    {
        return max(1, (int) Carbon::now()->diff(Carbon::now()->add($interval))->days);
    }
}
