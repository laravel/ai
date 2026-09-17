<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Files\StorableFile;

interface UploadsDocumentsToStore
{
    /**
     * Upload a document's contents directly into a vector store.
     */
    public function uploadDocumentToStore(string $storeId, StorableFile $file, array $metadata = []): string;
}
