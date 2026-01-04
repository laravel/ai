<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\StoreGateway;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\CreatedStoreResponse;
use Laravel\Ai\Responses\StoreResponse;

class OpenAiStoreGateway implements StoreGateway
{
    /**
     * Get a vector store by its ID.
     */
    public function getStore(StoreProvider $provider, string $storeId): StoreResponse
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

        return new StoreResponse(
            id: $response->json('id'),
            name: $response->json('name'),
        );
    }

    /**
     * Create a new vector store.
     */
    public function createStore(StoreProvider $provider, string $name): CreatedStoreResponse
    {
        try {
            $response = Http::withToken($provider->providerCredentials()['key'])
                ->post('https://api.openai.com/v1/vector_stores', [
                    'name' => $name,
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

        return new CreatedStoreResponse($response->json('id'));
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
