<?php

namespace Laravel\Ai;

use Closure;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Gateway\FakeFileGateway;
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
    public static function put(
        StorableFile|UploadedFile|string $file,
        ?string $mimeType = null,
        ?string $name = null,
        ?string $provider = null): StoredFileResponse
    {
        if (is_string($file)) {
            $file = (new Base64Document(base64_encode($file), $mimeType))->as($name);
        }

        if ($file instanceof UploadedFile) {
            $file = Base64Document::fromUpload($file)
                ->as($name ?? $file->getClientOriginalName());
        }

        return Ai::fakeableFileProvider($provider)->putFile($file, $mimeType, $name);
    }

    /**
     * Store the file at the given local path.
     */
    public static function putFromPath(string $path, ?string $mimeType = null, ?string $name = null, ?string $provider = null): StoredFileResponse
    {
        return static::put(Document::fromPath($path), $mimeType, $name, provider: $provider);
    }

    /**
     * Store the file at the given path on the given disk.
     */
    public static function putFromStorage(string $path, ?string $disk = null, ?string $name = null, ?string $provider = null): StoredFileResponse
    {
        return static::put(Document::fromStorage($path, $disk), name: $name, provider: $provider);
    }

    /**
     * Delete a file by its ID.
     */
    public static function delete(string $fileId, ?string $provider = null): void
    {
        Ai::fakeableFileProvider($provider)->deleteFile($fileId);
    }

    /**
     * Fake file operations.
     */
    public static function fake(Closure|array $responses = []): FakeFileGateway
    {
        return Ai::fakeFiles($responses);
    }

    /**
     * Get the fake file ID for a given store name.
     */
    public static function fakeId(string $for): string
    {
        return 'fake_file_'.md5($for);
    }

    /**
     * Assert that a file was stored matching a given truth test.
     */
    public static function assertStored(Closure $callback): void
    {
        Ai::assertFileUploaded($callback);
    }

    /**
     * Assert that a file was not stored matching a given truth test.
     */
    public static function assertNotStored(Closure $callback): void
    {
        Ai::assertFileNotUploaded($callback);
    }

    /**
     * Assert that no files were stored.
     */
    public static function assertNothingStored(): void
    {
        Ai::assertNoFilesUploaded();
    }

    /**
     * Assert that a file was deleted matching a given truth test.
     */
    public static function assertDeleted(Closure|string $callback): void
    {
        Ai::assertFileDeleted($callback);
    }

    /**
     * Assert that a file was not deleted matching a given truth test.
     */
    public static function assertNotDeleted(Closure|string $callback): void
    {
        Ai::assertFileNotDeleted($callback);
    }

    /**
     * Assert that no files were deleted.
     */
    public static function assertNothingDeleted(): void
    {
        Ai::assertNoFilesDeleted();
    }

    /**
     * Determine if file operations are faked.
     */
    public static function isFaked(): bool
    {
        return Ai::filesAreFaked();
    }
}
