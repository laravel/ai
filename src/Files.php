<?php

namespace Laravel\Ai;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Responses\FileResponse;
use Laravel\Ai\Responses\StoredFileResponse;

class Files
{
    /**
     * Get a file by its ID.
     */
    public static function get(string $fileId, ?string $provider = null): FileResponse
    {
        return Ai::fakeableFileProvider($provider)->getFile($fileId);
    }

    /**
     * Store the given file.
     */
    public static function put(StorableFile|UploadedFile|string $file, ?string $mime = null, ?string $provider = null): StoredFileResponse
    {
        return Ai::fakeableFileProvider($provider)->putFile($file, $mime);
    }

    /**
     * Delete a file by its ID.
     */
    public static function delete(string $fileId, ?string $provider = null): void
    {
        Ai::fakeableFileProvider($provider)->deleteFile($fileId);
    }
}
