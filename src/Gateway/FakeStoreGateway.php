<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\StoreGateway;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Responses\CreatedStoreResponse;
use Laravel\Ai\Responses\StoreResponse;
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
    public function getStore(StoreProvider $provider, string $storeId): StoreResponse
    {
        return $this->nextGetResponse($storeId);
    }

    /**
     * Get the next response for a get request.
     */
    protected function nextGetResponse(string $storeId): StoreResponse
    {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $storeId);

        return tap($this->marshalGetResponse(
            $response, $storeId
        ), fn () => $this->currentResponseIndex++);
    }

    /**
     * Marshal the given response into a StoreResponse instance.
     */
    protected function marshalGetResponse(mixed $response, string $storeId): StoreResponse
    {
        if ($response instanceof Closure) {
            $response = $response($storeId);
        }

        if (is_null($response)) {
            if ($this->preventStrayOperations) {
                throw new RuntimeException('Attempted store retrieval without a fake response.');
            }

            return new StoreResponse($storeId, name: 'fake-store');
        }

        if (is_string($response)) {
            return new StoreResponse($storeId, name: $response);
        }

        return $response;
    }

    /**
     * Create a new vector store.
     */
    public function createStore(StoreProvider $provider, string $name): CreatedStoreResponse
    {
        return new CreatedStoreResponse('vs_'.Str::random(24));
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
