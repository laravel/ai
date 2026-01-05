<?php

namespace Laravel\Ai\Providers\Concerns;

use DateInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Files\HasProviderId;
use Laravel\Ai\Events\CreatingStore;
use Laravel\Ai\Events\StoreCreated;
use Laravel\Ai\Events\StoreDeleted;
use Laravel\Ai\Store;

trait ManagesStores
{
    /**
     * Get a vector store by its ID.
     */
    public function getStore(string $storeId): Store
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
    ): Store {
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
            function (Store $store) use ($invocationId, $name, $description, $fileIds, $expiresWhenIdleFor) {
                $this->events->dispatch(new StoreCreated(
                    $invocationId, $this, $name, $description, $fileIds, $expiresWhenIdleFor, $store,
                ));
            }
        );
    }

    /**
     * Add a file to a vector store.
     */
    public function addFileToStore(string $storeId, HasProviderId $file): string
    {
        return $this->storeGateway()->addFile($this, $storeId, $file->id());
    }

    /**
     * Remove a file from a vector store.
     */
    public function removeFileFromStore(string $storeId, HasProviderId|string $fileId): bool
    {
        return $this->storeGateway()->removeFile(
            $this,
            $storeId,
            $fileId instanceof HasProviderId ? $fileId->id() : $fileId
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
