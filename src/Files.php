<?php

namespace Laravel\Ai;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Ai;
use Laravel\Ai\Files\StorableFile;

class Files
{
    /**
     * Get a file by its ID.
     */
    public static function get(string $fileId, ?string $provider = null): FileResponse
    {
        $provider = Ai::fakeableFileProvider($provider);

        // Get file...
    }

    /**
     * Store the given file.
     */
    public static function put(StorableFile|UploadedFile|string $file, ?string $mime = null, ?string $provider = null): StoredFileResponse
    {
        $provider = Ai::fakeableFileProvider($provider);

        // Store file...
    }

    /**
     * Delete a file by its ID.
     */
    public static function delete(string $fileId, ?string $provider = null): void
    {
        $provider = Ai::fakeableFileProvider($provider);

        // Store file...
    }
}
