<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Gateway\UploadsDocuments;
use RuntimeException;

trait UploadsStoreDocuments
{
    /**
     * Upload a document's contents directly into a vector store.
     */
    public function uploadDocumentToStore(string $storeId, StorableFile $file, array $metadata = []): string
    {
        $gateway = $this->storeGateway();

        if (! $gateway instanceof UploadsDocuments) {
            throw new RuntimeException('The provider\'s store gateway does not support direct document uploads.');
        }

        return $this->dispatchFileAddition(
            $storeId, $file->name() ?? '',
            fn () => $gateway->uploadDocument($this, $storeId, $file, $metadata),
        );
    }
}
