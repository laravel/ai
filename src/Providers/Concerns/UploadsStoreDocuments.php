<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Gateway\UploadsDocumentsToStore;
use LogicException;

trait UploadsStoreDocuments
{
    /**
     * Upload a document's contents directly into a vector store.
     */
    public function uploadDocumentToStore(string $storeId, StorableFile $file, array $metadata = []): string
    {
        $gateway = $this->storeGateway();

        if (! $gateway instanceof UploadsDocumentsToStore) {
            throw new LogicException('Store gateway ['.$gateway::class.'] does not support uploading documents directly.');
        }

        return $this->dispatchFileAddition(
            $storeId,
            null,
            fn () => $gateway->uploadDocument($this, $storeId, $file, $metadata),
        );
    }
}
