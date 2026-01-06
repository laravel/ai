<?php

namespace Laravel\Ai\Providers\Concerns;

use DateInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Files\HasProviderId;
use Laravel\Ai\Events\AddingFileToStore;
use Laravel\Ai\Events\CreatingStore;
use Laravel\Ai\Events\FileAddedToStore;
use Laravel\Ai\Events\FileRemovedFromStore;
use Laravel\Ai\Events\RemovingFileFromStore;
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
        $invocationId = (string) Str::uuid7();

        $this->events->dispatch(new AddingFileToStore(
            $invocationId, $this, $storeId, $file->id()
        ));

        return tap(
            $this->storeGateway()->addFile($this, $storeId, $file->id()),
            function (string $documentId) use ($invocationId, $storeId, $file) {
                $this->events->dispatch(new FileAddedToStore(
                    $invocationId, $this, $storeId, $file->id(), $documentId,
                ));
            }
        );
    }

    /**
     * Remove a file from a vector store.
     */
    public function removeFileFromStore(string $storeId, HasProviderId|string $documentId): bool
    {
        $invocationId = (string) Str::uuid7();

        $documentId = $documentId instanceof HasProviderId ? $documentId->id() : $documentId;

        if (Ai::storesAreFaked()) {
            Ai::recordFileRemoval($storeId, $documentId);
        }

        $this->events->dispatch(new RemovingFileFromStore(
            $invocationId, $this, $storeId, $documentId
        ));

        return tap(
            $this->storeGateway()->removeFile($this, $storeId, $documentId),
            function () use ($invocationId, $storeId, $documentId) {
                $this->events->dispatch(new FileRemovedFromStore(
                    $invocationId, $this, $storeId, $documentId,
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
