<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Events\FileDeleted;
use Laravel\Ai\Events\FileStored;
use Laravel\Ai\Events\StoringFile;
use Laravel\Ai\Events\StoringFileFailed;
use Laravel\Ai\Responses\FileResponse;
use Laravel\Ai\Responses\StoredFileResponse;
use Throwable;

trait ManagesFiles
{
    /**
     * Get a file by its ID.
     */
    public function getFile(string $fileId): FileResponse
    {
        return $this->fileGateway()->getFile($this, $fileId);
    }

    /**
     * Store the given file.
     */
    public function putFile(StorableFile $file): StoredFileResponse
    {
        $invocationId = (string) Str::uuid7();

        if (Ai::filesAreFaked()) {
            Ai::recordFileUpload($file);
        }

        $this->events->dispatch(new StoringFile(
            $invocationId, $this, $file
        ));

        return tap(
            $this->dispatchingFailure(
                fn () => $this->fileGateway()->putFile($this, $file),
                fn (Throwable $exception) => new StoringFileFailed($invocationId, $this, $file, $exception),
            ),
            function (StoredFileResponse $response) use ($invocationId, $file): void {
                $this->events->dispatch(new FileStored(
                    $invocationId, $this, $file, $response,
                ));
            }
        );
    }

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(string $fileId): void
    {
        $invocationId = (string) Str::uuid7();

        if (Ai::filesAreFaked()) {
            Ai::recordFileDeletion($fileId);
        }

        $this->fileGateway()->deleteFile($this, $fileId);

        $this->events->dispatch(new FileDeleted(
            $invocationId, $this, $fileId,
        ));
    }
}
