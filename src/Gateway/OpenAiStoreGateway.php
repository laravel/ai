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
use Laravel\Ai\Responses\CreatedStoreResponse;
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
    ): CreatedStoreResponse {
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

        return new CreatedStoreResponse($response->json('id'));
    }

    /**
     * Convert a DateInterval to days.
     */
    protected function intervalToDays(DateInterval $interval): int
    {
        return max(1, (int) Carbon::now()->diff(Carbon::now()->add($interval))->days);
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
}
