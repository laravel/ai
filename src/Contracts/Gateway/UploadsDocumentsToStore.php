<?php

namespace Laravel\Ai\Contracts\Gateway;

use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Providers\StoreProvider;

interface UploadsDocumentsToStore
{
    /**
     * Upload a document's contents directly into a vector store.
     */
    public function uploadDocument(
        StoreProvider $provider,
        string $storeId,
        StorableFile $file,
        array $metadata = [],
    ): string;
}
