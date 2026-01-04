<?php

namespace Laravel\Ai\Contracts\Providers;

use DateInterval;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\StoreGateway;
use Laravel\Ai\Responses\CreatedStoreResponse;
use Laravel\Ai\Responses\StoreResponse;

interface StoreProvider
{
    /**
     * Get a vector store by its ID.
     */
    public function getStore(string $storeId): StoreResponse;

    /**
     * Create a new vector store.
     */
    public function createStore(
        string $name,
        ?string $description = null,
        ?Collection $fileIds = null,
        ?DateInterval $expiresWhenIdleFor = null,
    ): CreatedStoreResponse;

    /**
     * Delete a vector store by its ID.
     */
    public function deleteStore(string $storeId): bool;

    /**
     * Get the provider's store gateway.
     */
    public function storeGateway(): StoreGateway;

    /**
     * Set the provider's store gateway.
     */
    public function useStoreGateway(StoreGateway $gateway): self;
}
