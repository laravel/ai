<?php

namespace Laravel\Ai;

use Closure;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Files\HasProviderId;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Files\ProviderDocument;
use Laravel\Ai\Responses\AddedDocumentResponse;
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
    public function add(
        StorableFile|UploadedFile|HasProviderId|string $file,
        array $metadata = [],
    ): AddedDocumentResponse {
        if ($file instanceof UploadedFile) {
            $file = Base64Document::fromUpload($file)
                ->as($this->name ?? $file->getClientOriginalName());
        }

        $originalFile = $file;

        if ($file instanceof StorableFile) {
            $file = $this->storeFile($file);
        }

        if (Ai::storesAreFaked()) {
            Ai::recordFileAddition($this->id, $file instanceof HasProviderId ? $file->id() : $file, $originalFile);
        }

        return new AddedDocumentResponse($this->provider->addFileToStore($this->id, match (true) {
            is_string($file) => new ProviderDocument($file),
            default => $file,
        }, $metadata), match (true) {
            $file instanceof HasProviderId => $file->id(),
            is_string($file) => $file,
            default => null,
        });
    }

    /**
     * Store the given file with the provider.
     */
    protected function storeFile(StorableFile $file): HasProviderId
    {
        return Files::put($file, provider: $this->provider->name());
    }

    /**
     * Remove a document from the store.
     */
    public function remove(HasProviderId|string $documentId, bool $deleteFile = false): bool
    {
        $removed = $this->provider->removeFileFromStore($this->id, $documentId);

        if ($deleteFile && $removed) {
            Files::delete(
                $documentId instanceof HasProviderId ? $documentId->id() : $documentId,
                provider: $this->provider->name()
            );
        }

        return $removed;
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

    /**
     * Assert that a file was added to the store.
     */
    public function assertAdded(Closure|string $fileId): self
    {
        Ai::assertFileAddedToStore($this->fileAssertionCallback($fileId));

        return $this;
    }

    /**
     * Assert that a file was not added to the store.
     */
    public function assertNotAdded(Closure|string $fileId): self
    {
        Ai::assertFileNotAddedToStore($this->fileAssertionCallback($fileId));

        return $this;
    }

    /**
     * Assert that a document was removed from the store.
     */
    public function assertRemoved(Closure|string $documentId): self
    {
        Ai::assertFileRemovedFromStore($this->fileAssertionCallback($documentId));

        return $this;
    }

    /**
     * Assert that a document was not removed from the store.
     */
    public function assertNotRemoved(Closure|string $documentId): self
    {
        Ai::assertFileNotRemovedFromStore($this->fileAssertionCallback($documentId));

        return $this;
    }

    /**
     * Get a callback for matching file assertions on this store.
     */
    protected function fileAssertionCallback(Closure|string $fileId): Closure
    {
        if ($fileId instanceof Closure) {
            return fn ($s, $f) => $s === $this->id && $fileId($f);
        }

        $expectedFileId = str_starts_with($fileId, 'fake_file_') ? $fileId : Files::fakeId($fileId);

        return fn ($s, $f) => $s === $this->id && $this->fileIdMatches($f, $expectedFileId);
    }

    /**
     * Determine if the given file matches the expected file ID.
     */
    protected function fileIdMatches(
        StorableFile|HasProviderId|string $file,
        string $expectedFileId,
    ): bool {
        return match (true) {
            $file instanceof HasProviderId => $file->id() === $expectedFileId,
            is_string($file) => $file === $expectedFileId,
            default => false,
        };
    }
}
