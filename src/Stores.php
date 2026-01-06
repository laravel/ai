<?php

namespace Laravel\Ai;

use Closure;
use DateInterval;
use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\FakeStoreGateway;

class Stores
{
    /**
     * Get a vector store by its ID.
     */
    public static function get(string $storeId, ?string $provider = null): Store
    {
        return Ai::fakeableStoreProvider($provider)->getStore($storeId);
    }

    /**
     * Create a new vector store.
     */
    public static function create(
        string $name,
        ?string $description = null,
        Collection|array $fileIds = [],
        ?DateInterval $expiresWhenIdleFor = null,
        ?string $provider = null): Store
    {
        return Ai::fakeableStoreProvider($provider)->createStore(
            $name, $description, Collection::make($fileIds), $expiresWhenIdleFor
        );
    }

    /**
     * Delete a vector store.
     */
    public static function delete(string $storeId, ?string $provider = null): bool
    {
        return Ai::fakeableStoreProvider($provider)->deleteStore($storeId);
    }

    /**
     * Fake store operations.
     */
    public static function fake(Closure|array $responses = [], bool $files = true): FakeStoreGateway
    {
        if ($files) {
            Files::fake();
        }

        return Ai::fakeStores($responses);
    }

    /**
     * Assert that a vector store was created by name.
     */
    public static function assertCreated(Closure|string $callback): void
    {
        Ai::assertStoreCreated($callback);
    }

    /**
     * Assert that a vector store was not created.
     */
    public static function assertNotCreated(Closure|string $callback): void
    {
        Ai::assertStoreNotCreated($callback);
    }

    /**
     * Assert that no vector stores were created.
     */
    public static function assertNothingCreated(): void
    {
        Ai::assertNoStoresCreated();
    }

    /**
     * Assert that a vector store was deleted.
     */
    public static function assertDeleted(Closure|string $callback): void
    {
        Ai::assertStoreDeleted($callback);
    }

    /**
     * Assert that a vector store was not deleted.
     */
    public static function assertNotDeleted(Closure|string $callback): void
    {
        Ai::assertStoreNotDeleted($callback);
    }

    /**
     * Assert that no vector stores were deleted.
     */
    public static function assertNothingDeleted(): void
    {
        Ai::assertNoStoresDeleted();
    }

    /**
     * Assert that a file was added to a store.
     */
    public static function assertFileAdded(Closure|string $storeId, ?string $fileId = null): void
    {
        Ai::assertFileAddedToStore($storeId, $fileId);
    }

    /**
     * Assert that a file was not added to a store.
     */
    public static function assertFileNotAdded(Closure|string $storeId, ?string $fileId = null): void
    {
        Ai::assertFileNotAddedToStore($storeId, $fileId);
    }

    /**
     * Assert that no files were added to any store.
     */
    public static function assertNoFilesAdded(): void
    {
        Ai::assertNoFilesAddedToStore();
    }

    /**
     * Assert that a file was removed from a store.
     */
    public static function assertFileRemoved(Closure|string $storeId, ?string $fileId = null): void
    {
        Ai::assertFileRemovedFromStore($storeId, $fileId);
    }

    /**
     * Assert that a file was not removed from a store.
     */
    public static function assertFileNotRemoved(Closure|string $storeId, ?string $fileId = null): void
    {
        Ai::assertFileNotRemovedFromStore($storeId, $fileId);
    }

    /**
     * Assert that no files were removed from any store.
     */
    public static function assertNoFilesRemoved(): void
    {
        Ai::assertNoFilesRemovedFromStore();
    }

    /**
     * Determine if store operations are faked.
     */
    public static function isFaked(): bool
    {
        return Ai::storesAreFaked();
    }
}
