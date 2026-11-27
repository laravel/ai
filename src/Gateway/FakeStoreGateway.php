<?php

namespace Laravel\Ai\Gateway;

use Closure;
use DateInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Ai\Contracts\Gateway\StoreGateway;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Enums\StoreDocumentStatus;
use Laravel\Ai\Responses\Data\StoreDocument;
use Laravel\Ai\Responses\Data\StoreFileCounts;
use Laravel\Ai\Store;
use Laravel\Ai\Stores;
use RuntimeException;

class FakeStoreGateway implements StoreGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayOperations = false;

    /**
     * The documents in each fake vector store, keyed by store ID and then document ID.
     *
     * @var array<string, array<string, StoreDocument>>
     */
    protected array $documents = [];

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
        ), fn (): int => $this->currentResponseIndex++);
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
    public function addFile(StoreProvider $provider, string $storeId, string $fileId, array $metadata = []): string
    {
        $this->documents[$storeId][$fileId] = new StoreDocument($fileId, $fileId, StoreDocumentStatus::Completed, $metadata);

        return $fileId;
    }

    /**
     * List the documents in a vector store.
     *
     * @return LazyCollection<int, StoreDocument>
     */
    public function listFiles(StoreProvider $provider, string $storeId): LazyCollection
    {
        return LazyCollection::make(fn () => array_values($this->documents[$storeId] ?? []));
    }

    /**
     * Remove a file from a vector store.
     */
    public function removeFile(StoreProvider $provider, string $storeId, string $documentId): bool
    {
        unset($this->documents[$storeId][$documentId]);

        return true;
    }

    /**
     * Delete a vector store by its ID.
     */
    public function deleteStore(StoreProvider $provider, string $storeId): bool
    {
        unset($this->documents[$storeId]);

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

    /**
     * Seed a fake vector store with the given documents or file IDs.
     *
     * @param  array<int, StoreDocument|string>  $documents
     */
    public function withDocuments(string $storeId, array $documents): self
    {
        foreach ($documents as $document) {
            $document = is_string($document) ? new StoreDocument($document, $document, StoreDocumentStatus::Completed) : $document;

            $this->documents[$storeId][$document->id] = $document;
        }

        return $this;
    }
}
