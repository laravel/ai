<?php

namespace Laravel\Ai\Gateway\Mistral;

use DateInterval;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\StoreGateway;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Responses\Data\StoreFileCounts;
use Laravel\Ai\Store;
use RuntimeException;

class MistralStoreGateway implements StoreGateway
{
    use Concerns\CreatesMistralClient;
    use HandlesFailoverErrors;

    /**
     * Get a vector store by its ID.
     */
    public function getStore(StoreProvider $provider, string $storeId): Store
    {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->get("libraries/{$storeId}")
        );

        return new Store(
            provider: $provider,
            id: $response->json('id'),
            name: $response->json('name'),
            fileCounts: $this->fileCounts($provider, $response->json('id'), $response->json('nb_documents', 0)),
            ready: true,
        );
    }

    /**
     * Create a new vector store.
     *
     * Mistral libraries do not support idle expiration; the interval is ignored.
     */
    public function createStore(
        StoreProvider $provider,
        string $name,
        ?string $description = null,
        ?Collection $fileIds = null,
        ?DateInterval $expiresWhenIdleFor = null,
    ): Store {
        if ($fileIds?->isNotEmpty()) {
            throw new RuntimeException(
                'Mistral does not support attaching existing files to a library. Add documents via [$store->add()] instead.'
            );
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->post('libraries', array_filter([
                'name' => $name,
                'description' => $description,
            ]))
        );

        return $this->getStore($provider, $response->json('id'));
    }

    /**
     * Add a file to a vector store.
     */
    public function addFile(StoreProvider $provider, string $storeId, string $fileId, array $metadata = []): string
    {
        throw new RuntimeException(
            'Mistral does not support adding existing files to a library by ID. Pass the document contents to [$store->add()] instead.'
        );
    }

    /**
     * Remove a file from a vector store.
     */
    public function removeFile(StoreProvider $provider, string $storeId, string $documentId): bool
    {
        $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->delete("libraries/{$storeId}/documents/{$documentId}")
        );

        return true;
    }

    /**
     * Delete a vector store by its ID.
     */
    public function deleteStore(StoreProvider $provider, string $storeId): bool
    {
        $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->delete("libraries/{$storeId}")
        );

        return true;
    }

    /**
     * Derive store file counts from the library's document processing statuses.
     */
    protected function fileCounts(StoreProvider $provider, string $storeId, int $totalDocuments): StoreFileCounts
    {
        if ($totalDocuments === 0) {
            return new StoreFileCounts(0, 0, 0);
        }

        $documents = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->get("libraries/{$storeId}/documents", ['page_size' => 100])
        )->json('data', []);

        $statuses = (new Collection($documents))->countBy(fn (array $document) => match ($document['process_status'] ?? null) {
            'done' => 'completed',
            'error' => 'failed',
            default => 'pending',
        });

        return new StoreFileCounts(
            completed: $statuses['completed'] ?? 0,
            pending: $statuses['pending'] ?? 0,
            failed: $statuses['failed'] ?? 0,
        );
    }
}
