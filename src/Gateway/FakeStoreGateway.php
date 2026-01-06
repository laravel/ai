<?php

namespace Laravel\Ai\Gateway;

use Closure;
use DateInterval;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\StoreGateway;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Responses\Data\StoreFileCounts;
use Laravel\Ai\Store;
use Laravel\Ai\Stores;
use RuntimeException;

class FakeStoreGateway implements StoreGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayOperations = false;

    public function __construct(
        protected Closure|array $responses = [],
    ) {}

    /**
     * Get a vector store by its ID.
     */
    public function getStore(StoreProvider $provider, string $storeId): Store
    {
        return $this->nextGetResponse($provider, $storeId);
    }

    /**
     * Get the next response for a get request.
     */
    protected function nextGetResponse(StoreProvider $provider, string $storeId): Store
    {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $storeId);

        return tap($this->marshalGetResponse(
            $provider, $response, $storeId
        ), fn () => $this->currentResponseIndex++);
    }

    /**
     * Marshal the given response into a Store instance.
     */
    protected function marshalGetResponse(StoreProvider $provider, mixed $response, string $storeId): Store
    {
        if ($response instanceof Closure) {
            $response = $response($storeId);
        }

        if (is_null($response)) {
            if ($this->preventStrayOperations) {
                throw new RuntimeException('Attempted store retrieval without a fake response.');
            }

            return new Store(
                provider: $provider,
                id: $storeId,
                name: 'fake-store',
                fileCounts: new StoreFileCounts(0, 0, 0),
                ready: true,
            );
        }

        if (is_string($response)) {
            return new Store(
                provider: $provider,
                id: $storeId,
                name: $response,
                fileCounts: new StoreFileCounts(0, 0, 0),
                ready: true,
            );
        }

        return $response;
    }

    /**
     * Create a new vector store.
     */
    public function createStore(
        StoreProvider $provider,
        string $name,
        ?string $description = null,
        ?Collection $fileIds = null,
        ?DateInterval $expiresWhenIdleFor = null,
    ): Store {
        return new Store(
            provider: $provider,
            id: Stores::fakeId($name),
            name: $name,
            fileCounts: new StoreFileCounts(0, 0, 0),
            ready: true,
        );
    }

    /**
     * Add a file to a vector store.
     */
    public function addFile(StoreProvider $provider, string $storeId, string $fileId): string
    {
        return $fileId;
    }

    /**
     * Remove a file from a vector store.
     */
    public function removeFile(StoreProvider $provider, string $storeId, string $fileId): bool
    {
        return true;
    }

    /**
     * Delete a vector store by its ID.
     */
    public function deleteStore(StoreProvider $provider, string $storeId): bool
    {
        return true;
    }

    /**
     * Indicate that an exception should be thrown if any store operation is not faked.
     */
    public function preventStrayOperations(bool $prevent = true): self
    {
        $this->preventStrayOperations = $prevent;

        return $this;
    }
}
