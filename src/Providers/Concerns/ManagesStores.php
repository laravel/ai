<?php

namespace Laravel\Ai\Providers\Concerns;

use DateInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\CreatingStore;
use Laravel\Ai\Events\StoreCreated;
use Laravel\Ai\Events\StoreDeleted;
use Laravel\Ai\Responses\CreatedStoreResponse;
use Laravel\Ai\Responses\StoreResponse;

trait ManagesStores
{
    /**
     * Get a vector store by its ID.
     */
    public function getStore(string $storeId): StoreResponse
    {
        return $this->storeGateway()->getStore($this, $storeId);
    }

    /**
     * Create a new vector store.
     */
    public function createStore(
        string $name,
        ?string $description = null,
        ?Collection $fileIds = null,
        ?DateInterval $expiresWhenIdleFor = null,
    ): CreatedStoreResponse {
        $invocationId = (string) Str::uuid7();

        $fileIds ??= new Collection;

        if (Ai::storesAreFaked()) {
            Ai::recordStoreCreation($name, $description, $fileIds, $expiresWhenIdleFor);
        }

        $this->events->dispatch(new CreatingStore(
            $invocationId, $this, $name, $description, $fileIds, $expiresWhenIdleFor
        ));

        return tap(
            $this->storeGateway()->createStore($this, $name, $description, $fileIds, $expiresWhenIdleFor),
            function (CreatedStoreResponse $response) use ($invocationId, $name, $description, $fileIds, $expiresWhenIdleFor) {
                $this->events->dispatch(new StoreCreated(
                    $invocationId, $this, $name, $description, $fileIds, $expiresWhenIdleFor, $response,
                ));
            }
        );
    }

    /**
     * Delete a vector store by its ID.
     */
    public function deleteStore(string $storeId): bool
    {
        $invocationId = (string) Str::uuid7();

        if (Ai::storesAreFaked()) {
            Ai::recordStoreDeletion($storeId);
        }

        $result = $this->storeGateway()->deleteStore($this, $storeId);

        $this->events->dispatch(new StoreDeleted(
            $invocationId, $this, $storeId,
        ));

        return $result;
    }
}
