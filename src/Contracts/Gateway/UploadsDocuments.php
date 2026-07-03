<?php

namespace Laravel\Ai\Contracts\Gateway;

use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Providers\StoreProvider;

interface UploadsDocuments
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
