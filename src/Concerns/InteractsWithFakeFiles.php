<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Gateway\FakeFileGateway;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeFiles
{
    /**
     * The fake file gateway instance.
     */
    protected ?FakeFileGateway $fakeFileGateway = null;

    /**
     * All of the recorded file uploads.
     */
    protected array $recordedFileUploads = [];

    /**
     * All of the recorded file deletions.
     */
    protected array $recordedFileDeletions = [];

    /**
     * Fake file operations.
     */
    public function fakeFiles(Closure|array $responses = []): FakeFileGateway
    {
        return $this->fakeFileGateway = new FakeFileGateway($responses);
    }

    /**
     * Record a file upload.
     */
    public function recordFileUpload(StorableFile|UploadedFile|string $file, ?string $mime): self
    {
        $this->recordedFileUploads[] = [
            'file' => $file,
            'mime' => $mime,
        ];

        return $this;
    }

    /**
     * Record a file deletion.
     */
    public function recordFileDeletion(string $fileId): self
    {
        $this->recordedFileDeletions[] = $fileId;

        return $this;
    }

    /**
     * Assert that a file was uploaded matching a given truth test.
     */
    public function assertFileUploaded(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedFileUploads))->filter(function (array $upload) use ($callback) {
                return $callback($upload['file'], $upload['mime']);
            })->count() > 0,
            'An expected file upload was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a file was not uploaded matching a given truth test.
     */
    public function assertFileNotUploaded(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedFileUploads))->filter(function (array $upload) use ($callback) {
                return $callback($upload['file'], $upload['mime']);
            })->count() === 0,
            'An unexpected file upload was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no files were uploaded.
     */
    public function assertNoFilesUploaded(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedFileUploads,
            'Unexpected file uploads were recorded.'
        );

        return $this;
    }

    /**
     * Assert that a file was deleted matching a given truth test.
     */
    public function assertFileDeleted(Closure|string $callback): self
    {
        if (is_string($callback)) {
            $fileId = $callback;
            $callback = fn ($id) => $id === $fileId;
        }

        PHPUnit::assertTrue(
            (new Collection($this->recordedFileDeletions))->filter(function (string $id) use ($callback) {
                return $callback($id);
            })->count() > 0,
            'An expected file deletion was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a file was not deleted matching a given truth test.
     */
    public function assertFileNotDeleted(Closure|string $callback): self
    {
        if (is_string($callback)) {
            $fileId = $callback;
            $callback = fn ($id) => $id === $fileId;
        }

        PHPUnit::assertTrue(
            (new Collection($this->recordedFileDeletions))->filter(function (string $id) use ($callback) {
                return $callback($id);
            })->count() === 0,
            'An unexpected file deletion was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no files were deleted.
     */
    public function assertNoFilesDeleted(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedFileDeletions,
            'Unexpected file deletions were recorded.'
        );

        return $this;
    }

    /**
     * Determine if file operations are faked.
     */
    public function filesAreFaked(): bool
    {
        return $this->fakeFileGateway !== null;
    }

    /**
     * Get the fake file gateway.
     */
    public function fakeFileGateway(): ?FakeFileGateway
    {
        return $this->fakeFileGateway;
    }
}
