<?php

namespace Laravel\Ai\Contracts\Gateway;

use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Responses\CreatedStoreResponse;
use Laravel\Ai\Responses\StoreResponse;

interface StoreGateway
{
    /**
     * Get a vector store by its ID.
     */
    public function getStore(
        StoreProvider $provider,
        string $storeId,
    ): StoreResponse;

    /**
     * Create a new vector store.
     */
    public function createStore(
        StoreProvider $provider,
        string $name,
    ): CreatedStoreResponse;

    /**
     * Delete a vector store by its ID.
     */
    public function deleteStore(
        StoreProvider $provider,
        string $storeId,
    ): bool;
}
