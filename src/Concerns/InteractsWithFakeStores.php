<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\FakeStoreGateway;
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
     * Fake store operations.
     */
    public function fakeStores(Closure|array $responses = []): FakeStoreGateway
    {
        return $this->fakeStoreGateway = new FakeStoreGateway($responses);
    }

    /**
     * Record a store creation.
     */
    public function recordStoreCreation(string $name): self
    {
        $this->recordedStoreCreations[] = [
            'name' => $name,
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
     * Assert that a store was created matching a given truth test.
     */
    public function assertStoreCreated(Closure|string $callback): self
    {
        if (is_string($callback)) {
            $name = $callback;
            $callback = fn ($creation) => $creation['name'] === $name;
        }

        PHPUnit::assertTrue(
            (new Collection($this->recordedStoreCreations))->filter(function (array $creation) use ($callback) {
                return $callback($creation);
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
            $callback = fn ($creation) => $creation['name'] === $name;
        }

        PHPUnit::assertTrue(
            (new Collection($this->recordedStoreCreations))->filter(function (array $creation) use ($callback) {
                return $callback($creation);
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
