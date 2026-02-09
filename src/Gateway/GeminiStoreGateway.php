<?php

namespace Laravel\Ai\Gateway;

use DateInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\StoreGateway;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Responses\Data\StoreFileCounts;
use Laravel\Ai\Store;

class GeminiStoreGateway implements StoreGateway
{
    use Concerns\HandlesRateLimiting;

    /**
     * Get a vector store by its ID.
     */
    public function getStore(StoreProvider $provider, string $storeId): Store
    {
        $storeId = $this->normalizeStoreId($storeId);

        $response = $this->withRateLimitHandling($provider->name(), fn () => Http::withHeaders([
            'x-goog-api-key' => $provider->providerCredentials()['key'],
        ])->get("https://generativelanguage.googleapis.com/v1beta/{$storeId}")->throw());

        return new Store(
            provider: $provider,
            id: $response->json('name'),
            name: $response->json('displayName'),
            fileCounts: new StoreFileCounts(
                completed: $response->json('activeDocumentsCount', 0),
                pending: $response->json('pendingDocumentsCount', 0),
                failed: $response->json('failedDocumentsCount', 0),
            ),
            ready: true,
        );
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
        $fileIds ??= new Collection;

        $response = $this->withRateLimitHandling($provider->name(), fn () => Http::withHeaders([
            'x-goog-api-key' => $provider->providerCredentials()['key'],
        ])->post('https://generativelanguage.googleapis.com/v1beta/fileSearchStores', [
            'displayName' => $name,
        ])->throw());

        $store = $this->getStore($provider, $response->json('name'));

        if ($fileIds->isNotEmpty()) {
            foreach ($fileIds as $fileId) {
                $this->addFile($provider, $store->id, $fileId);
            }
        }

        return $store;
    }

    /**
     * Add a file to a vector store.
     */
    public function addFile(StoreProvider $provider, string $storeId, string $fileId, array $metadata = []): string
    {
        $storeId = $this->normalizeStoreId($storeId);
        $fileId = $this->normalizeFileId($fileId);

        $response = $this->withRateLimitHandling($provider->name(), fn () => Http::withHeaders([
            'x-goog-api-key' => $provider->providerCredentials()['key'],
        ])->post("https://generativelanguage.googleapis.com/v1beta/{$storeId}:importFile", array_filter([
            'fileName' => $fileId,
            'customMetadata' => ! empty($metadata) ? $this->formatMetadata($metadata) : null,
        ]))->throw());

        return basename($response->json('name'));
    }

    /**
     * Format metadata for Gemini's custom_metadata format.
     */
    protected function formatMetadata(array $metadata): array
    {
        return (new Collection($metadata))->map(function ($value, $key) {
            return match (true) {
                is_numeric($value) => ['key' => $key, 'numericValue' => $value],
                is_array($value) => ['key' => $key, 'stringListValue' => ['values' => $value]],
                default => ['key' => $key, 'stringValue' => (string) $value],
            };
        })->values()->all();
    }

    /**
     * Remove a file from a vector store.
     */
    public function removeFile(StoreProvider $provider, string $storeId, string $documentId): bool
    {
        $storeId = $this->normalizeStoreId($storeId);
        $documentId = $this->normalizeDocumentId($storeId, $documentId);

        $this->withRateLimitHandling($provider->name(), fn () => Http::withHeaders([
            'x-goog-api-key' => $provider->providerCredentials()['key'],
        ])->delete("https://generativelanguage.googleapis.com/v1beta/{$documentId}", [
            'force' => true,
        ])->throw());

        return true;
    }

    /**
     * Delete a vector store by its ID.
     */
    public function deleteStore(StoreProvider $provider, string $storeId): bool
    {
        $storeId = $this->normalizeStoreId($storeId);

        $this->withRateLimitHandling($provider->name(), fn () => Http::withHeaders([
            'x-goog-api-key' => $provider->providerCredentials()['key'],
        ])->delete("https://generativelanguage.googleapis.com/v1beta/{$storeId}")->throw());

        return true;
    }

    /**
     * Normalize the store ID to include the resource prefix.
     */
    protected function normalizeStoreId(string $storeId): string
    {
        return str_starts_with($storeId, 'fileSearchStores/')
            ? $storeId
            : "fileSearchStores/{$storeId}";
    }

    /**
     * Normalize the file ID to include the resource prefix.
     */
    protected function normalizeFileId(string $fileId): string
    {
        return str_starts_with($fileId, 'files/')
            ? $fileId
            : "files/{$fileId}";
    }

    /**
     * Normalize the document ID to include the full resource path.
     */
    protected function normalizeDocumentId(string $storeId, string $documentId): string
    {
        // Already a full document path...
        if (str_starts_with($documentId, 'fileSearchStores/')) {
            return $documentId;
        }

        $documentId = match (true) {
            str_starts_with($documentId, 'documents/') => substr($documentId, 10),
            str_starts_with($documentId, 'files/') => substr($documentId, 6),
            default => $documentId,
        };

        return "{$storeId}/documents/{$documentId}";
    }
}
