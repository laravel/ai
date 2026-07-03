<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Events\AddingFileToStore;
use Laravel\Ai\Events\FileAddedToStore;

trait UploadsStoreDocuments
{
    /**
     * Upload a document's contents directly into a vector store.
     */
    public function uploadDocumentToStore(string $storeId, StorableFile $file, array $metadata = []): string
    {
        $invocationId = (string) Str::uuid7();

        $this->events->dispatch(new AddingFileToStore(
            $invocationId, $this, $storeId, $file->name() ?? ''
        ));

        return tap(
            $this->storeGateway()->uploadDocument($this, $storeId, $file, $metadata),
            function (string $documentId) use ($invocationId, $storeId, $file) {
                $this->events->dispatch(new FileAddedToStore(
                    $invocationId, $this, $storeId, $file->name() ?? '', $documentId,
                ));
            }
        );
    }
}
