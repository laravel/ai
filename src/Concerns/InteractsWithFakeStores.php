<?php

namespace Laravel\Ai\Concerns;

use Closure;
use DateInterval;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Files\HasProviderId;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files;
use Laravel\Ai\Gateway\FakeStoreGateway;
use Laravel\Ai\Stores;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeStores
{
    /**
     * The fake store gateway instance.
     */
    protected ?FakeStoreGateway $fakeStoreGateway = null;

    /**
     * All of the recorded store creations.
     */
    protected array $recordedStoreCreations = [];

    /**
     * All of the recorded store deletions.
     */
    protected array $recordedStoreDeletions = [];

    /**
     * All of the recorded file additions.
     */
    protected array $recordedFileAdditions = [];

    /**
     * All of the recorded file removals.
     */
    protected array $recordedFileRemovals = [];

    /**
     * Fake store operations.
     */
    public function fakeStores(Closure|array $responses = []): FakeStoreGateway
    {
        return $this->fakeStoreGateway = new FakeStoreGateway($responses);
    }

    /**
     * Record a store creation.
     */
    public function recordStoreCreation(
        string $name,
        ?string $description = null,
        ?Collection $fileIds = null,
        ?DateInterval $expiresWhenIdleFor = null,
    ): self {
        $this->recordedStoreCreations[] = [
            'name' => $name,
            'description' => $description,
            'fileIds' => $fileIds,
            'expiresWhenIdleFor' => $expiresWhenIdleFor,
        ];

        return $this;
    }

    /**
     * Record a store deletion.
     */
    public function recordStoreDeletion(string $storeId): self
    {
        $this->recordedStoreDeletions[] = $storeId;

        return $this;
    }

    /**
     * Record a file addition to a store.
     */
    public function recordFileAddition(
        string $storeId,
        string $fileId,
        StorableFile|UploadedFile|HasProviderId|string $file,
    ): self {
        $this->recordedFileAdditions[] = [
            'storeId' => $storeId,
            'fileId' => $fileId,
            'file' => $file,
        ];

        return $this;
    }

    /**
     * Record a file removal from a store.
     */
    public function recordFileRemoval(string $storeId, string $fileId): self
    {
        $this->recordedFileRemovals[] = [
            'storeId' => $storeId,
            'fileId' => $fileId,
        ];

        return $this;
    }

    /**
     * Assert that a store was created matching a given truth test.
     */
    public function assertStoreCreated(Closure|string $callback): self
    {
        if (is_string($callback)) {
            $name = $callback;
            $callback = fn ($n) => $n === $name;
        }

        PHPUnit::assertTrue(
            (new Collection($this->recordedStoreCreations))->filter(function (array $creation) use ($callback) {
                return $callback(
                    $creation['name'],
                    $creation['description'],
                    $creation['fileIds'],
                    $creation['expiresWhenIdleFor'],
                );
            })->count() > 0,
            'An expected store creation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a store was not created matching a given truth test.
     */
    public function assertStoreNotCreated(Closure|string $callback): self
    {
        if (is_string($callback)) {
            $name = $callback;
            $callback = fn ($n) => $n === $name;
        }

        PHPUnit::assertTrue(
            (new Collection($this->recordedStoreCreations))->filter(function (array $creation) use ($callback) {
                return $callback(
                    $creation['name'],
                    $creation['description'],
                    $creation['fileIds'],
                    $creation['expiresWhenIdleFor'],
                );
            })->count() === 0,
            'An unexpected store creation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no stores were created.
     */
    public function assertNoStoresCreated(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedStoreCreations,
            'Unexpected store creations were recorded.'
        );

        return $this;
    }

    /**
     * Assert that a store was deleted matching a given truth test.
     */
    public function assertStoreDeleted(Closure|string $callback): self
    {
        if (is_string($callback)) {
            $storeId = $callback;
            $callback = fn ($id) => $id === $storeId;
        }

        PHPUnit::assertTrue(
            (new Collection($this->recordedStoreDeletions))->filter(function (string $id) use ($callback) {
                return $callback($id);
            })->count() > 0,
            'An expected store deletion was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a store was not deleted matching a given truth test.
     */
    public function assertStoreNotDeleted(Closure|string $callback): self
    {
        if (is_string($callback)) {
            $storeId = $callback;
            $callback = fn ($id) => $id === $storeId;
        }

        PHPUnit::assertTrue(
            (new Collection($this->recordedStoreDeletions))->filter(function (string $id) use ($callback) {
                return $callback($id);
            })->count() === 0,
            'An unexpected store deletion was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no stores were deleted.
     */
    public function assertNoStoresDeleted(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedStoreDeletions,
            'Unexpected store deletions were recorded.'
        );

        return $this;
    }

    /**
     * Assert that a file was added to a store matching a given truth test.
     */
    public function assertFileAddedToStore(Closure|string $storeId, ?string $fileId = null): self
    {
        $callback = $this->fileMatchingCallback($storeId, $fileId);

        PHPUnit::assertTrue(
            (new Collection($this->recordedFileAdditions))->filter(function (array $addition) use ($callback) {
                return $callback($addition['storeId'], $addition['file']);
            })->count() > 0,
            'An expected file addition was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a file was not added to a store matching a given truth test.
     */
    public function assertFileNotAddedToStore(Closure|string $storeId, ?string $fileId = null): self
    {
        $callback = $this->fileMatchingCallback($storeId, $fileId);

        PHPUnit::assertTrue(
            (new Collection($this->recordedFileAdditions))->filter(function (array $addition) use ($callback) {
                return $callback($addition['storeId'], $addition['file']);
            })->count() === 0,
            'An unexpected file addition was recorded.'
        );

        return $this;
    }

    /**
     * Assert that a file was removed from a store matching a given truth test.
     */
    public function assertFileRemovedFromStore(Closure|string $storeId, ?string $fileId = null): self
    {
        $callback = $this->fileMatchingCallback($storeId, $fileId);

        PHPUnit::assertTrue(
            (new Collection($this->recordedFileRemovals))->filter(function (array $removal) use ($callback) {
                return $callback($removal['storeId'], $removal['fileId']);
            })->count() > 0,
            'An expected file removal was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a file was not removed from a store matching a given truth test.
     */
    public function assertFileNotRemovedFromStore(Closure|string $storeId, ?string $fileId = null): self
    {
        $callback = $this->fileMatchingCallback($storeId, $fileId);

        PHPUnit::assertTrue(
            (new Collection($this->recordedFileRemovals))->filter(function (array $removal) use ($callback) {
                return $callback($removal['storeId'], $removal['fileId']);
            })->count() === 0,
            'An unexpected file removal was recorded.'
        );

        return $this;
    }

    /**
     * Get a callback for matching store and file IDs.
     */
    protected function fileMatchingCallback(Closure|string $storeId, ?string $fileId): Closure
    {
        if ($storeId instanceof Closure) {
            return $storeId;
        }

        $expectedStoreId = str_starts_with($storeId, 'fake_store_') ? $storeId : Stores::fakeId($storeId);
        $expectedFileId = str_starts_with($fileId, 'fake_file_') ? $fileId : Files::fakeId($fileId);

        return fn ($s, $f) => $s === $expectedStoreId && $this->fileIdMatches($f, $expectedFileId);
    }

    /**
     * Determine if the given file matches the expected file ID.
     */
    protected function fileIdMatches(StorableFile|UploadedFile|HasProviderId|string $file, string $expectedFileId): bool
    {
        return match (true) {
            $file instanceof HasProviderId => $file->id() === $expectedFileId,
            is_string($file) => $file === $expectedFileId,
            default => false,
        };
    }

    /**
     * Determine if store operations are faked.
     */
    public function storesAreFaked(): bool
    {
        return $this->fakeStoreGateway !== null;
    }

    /**
     * Get the fake store gateway.
     */
    public function fakeStoreGateway(): ?FakeStoreGateway
    {
        return $this->fakeStoreGateway;
    }
}
