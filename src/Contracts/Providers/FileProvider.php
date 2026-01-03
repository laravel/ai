<?php

namespace Laravel\Ai\Contracts\Providers;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Files\StorableFile;

interface FileProvider
{
    /**
     * Get a file by its ID.
     */
    public function getFile(string $fileId): FileResponse;

    /**
     * Store the given file.
     */
    public function putFile(StorableFile|UploadedFile|string $file, ?string $mime = null): StoredFileResponse;

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(string $fileId): void;

    /**
     * Get the provider's file gateway.
     */
    public function fileGateway(): FileGateway;

    /**
     * Set the provider's file gateway.
     */
    public function useFileGateway(FileGateway $gateway): self;
}
