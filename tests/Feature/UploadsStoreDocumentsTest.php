<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\StoreGateway;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Providers\Concerns\UploadsStoreDocuments;
use Laravel\Ai\Store;

test('uploading a document throws when the store gateway does not support direct uploads', function () {
    $gateway = new class implements StoreGateway
    {
        public function getStore(StoreProvider $provider, string $storeId): Store
        {
            throw new BadMethodCallException;
        }

        public function createStore(StoreProvider $provider, string $name, ?string $description = null, ?Collection $fileIds = null, ?DateInterval $expiresWhenIdleFor = null): Store
        {
            throw new BadMethodCallException;
        }

        public function addFile(StoreProvider $provider, string $storeId, string $fileId, array $metadata = []): string
        {
            throw new BadMethodCallException;
        }

        public function removeFile(StoreProvider $provider, string $storeId, string $documentId): bool
        {
            throw new BadMethodCallException;
        }

        public function deleteStore(StoreProvider $provider, string $storeId): bool
        {
            throw new BadMethodCallException;
        }
    };

    $provider = new class($gateway)
    {
        use UploadsStoreDocuments;

        public function __construct(protected StoreGateway $gateway) {}

        public function storeGateway(): StoreGateway
        {
            return $this->gateway;
        }
    };

    $provider->uploadDocumentToStore('lib-123', Document::fromString('Hello, world!', 'text/plain'));
})->throws(RuntimeException::class, 'does not support direct document uploads');
