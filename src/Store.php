<?php

namespace Laravel\Ai;

use Laravel\Ai\Contracts\Files\HasProviderId;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Files\ProviderDocument;
use Laravel\Ai\Responses\Data\StoreFileCounts;

class Store
{
    public function __construct(
        protected FileProvider&StoreProvider $provider,
        public readonly string $id,
        public readonly ?string $name,
        public readonly StoreFileCounts $fileCounts,
        public readonly bool $ready,
    ) {}

    /**
     * Add a file to the store.
     */
    public function add(StorableFile|HasProviderId|string $file): string
    {
        return $this->provider->addFileToStore($this->id, match (true) {
            is_string($file) => new ProviderDocument($id),
            $file instanceof StorableFile => $this->storeFile($file),
            default => $file,
        });
    }

    /**
     * Store the given file with the provider.
     */
    protected function storeFile(StorableFile $file): HasProviderId
    {
        return Files::put($file);
    }

    /**
     * Remove a file from the store.
     */
    public function remove(HasProviderId|string $file): bool
    {
        return $this->provider->removeFileFromStore($this->id, $file);
    }

    /**
     * Refresh the store from the provider.
     */
    public function refresh(): self
    {
        return $this->provider->getStore($this->id);
    }

    /**
     * Delete the store from the provider.
     */
    public function delete(): bool
    {
        return $this->provider->deleteStore($this->id);
    }
}
