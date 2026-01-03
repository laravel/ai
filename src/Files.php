<?php

namespace Laravel\Ai;

use Closure;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Files\StorableFile;
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
    public static function put(StorableFile|UploadedFile|string $file, ?string $mime = null, ?string $provider = null): StoredFileResponse
    {
        return Ai::fakeableFileProvider($provider)->putFile($file, $mime);
    }

    /**
     * Store the file at the given local path.
     */
    public static function putFromPath(string $path, ?string $mime = null, ?string $provider = null): StoredFileResponse
    {
        return static::put(Document::fromPath($path), $mime, $provider);
    }

    /**
     * Store the file at the given path on the given disk.
     */
    public static function putFromStorage(string $path, ?string $disk = null, ?string $provider = null): StoredFileResponse
    {
        return static::put(Document::fromStorage($path, $disk), provider: $provider);
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
     * Assert that a file was uploaded matching a given truth test.
     */
    public static function assertUploaded(Closure $callback): void
    {
        Ai::assertFileUploaded($callback);
    }

    /**
     * Assert that a file was not uploaded matching a given truth test.
     */
    public static function assertNotUploaded(Closure $callback): void
    {
        Ai::assertFileNotUploaded($callback);
    }

    /**
     * Assert that no files were uploaded.
     */
    public static function assertNothingUploaded(): void
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
