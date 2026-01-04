<?php

namespace Laravel\Ai\Providers\Concerns;

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
    public function createStore(string $name): CreatedStoreResponse
    {
        $invocationId = (string) Str::uuid7();

        if (Ai::storesAreFaked()) {
            Ai::recordStoreCreation($name);
        }

        $this->events->dispatch(new CreatingStore(
            $invocationId, $this, $name
        ));

        return tap(
            $this->storeGateway()->createStore($this, $name),
            function (CreatedStoreResponse $response) use ($invocationId, $name) {
                $this->events->dispatch(new StoreCreated(
                    $invocationId, $this, $name, $response,
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
