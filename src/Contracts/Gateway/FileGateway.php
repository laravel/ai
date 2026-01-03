<?php

namespace Laravel\Ai\Contracts\Gateway;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Responses\FileResponse;
use Laravel\Ai\Responses\StoredFileResponse;

interface FileGateway
{
    /**
     * Get a file by its ID.
     */
    public function getFile(
        FileProvider $provider,
        string $fileId,
    ): FileResponse;

    /**
     * Store the given file.
     */
    public function putFile(
        FileProvider $provider,
        StorableFile|UploadedFile|string $file,
        ?string $mime = null,
    ): StoredFileResponse;

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(
        FileProvider $provider,
        string $fileId,
    ): void;
}
