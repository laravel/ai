<?php

namespace Laravel\Ai\Contracts\Gateway;

use DateInterval;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Store;

interface StoreGateway
{
    /**
     * Get a vector store by its ID.
     */
    public function getStore(
        StoreProvider $provider,
        string $storeId,
    ): Store;

    /**
     * Create a new vector store.
     */
    public function createStore(
        StoreProvider $provider,
        string $name,
        ?string $description = null,
        ?Collection $fileIds = null,
        ?DateInterval $expiresWhenIdleFor = null,
    ): Store;

    /**
     * Add a file to a vector store.
     */
    public function addFile(
        StoreProvider $provider,
        string $storeId,
        string $fileId,
    ): string;

    /**
     * Remove a file from a vector store.
     */
    public function removeFile(
        StoreProvider $provider,
        string $storeId,
        string $fileId,
    ): bool;

    /**
     * Delete a vector store by its ID.
     */
    public function deleteStore(
        StoreProvider $provider,
        string $storeId,
    ): bool;
}
