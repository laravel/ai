<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Events\FileDeleted;
use Laravel\Ai\Events\FileStored;
use Laravel\Ai\Events\StoringFile;
use Laravel\Ai\Responses\FileResponse;
use Laravel\Ai\Responses\StoredFileResponse;

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
    public function putFile(StorableFile|UploadedFile|string $file, ?string $mime = null): StoredFileResponse
    {
        $invocationId = (string) Str::uuid7();

        if (Ai::filesAreFaked()) {
            Ai::recordFileUpload($file, $mime);
        }

        $this->events->dispatch(new StoringFile(
            $invocationId, $this, $file, $mime,
        ));

        return tap(
            $this->fileGateway()->putFile($this, $file, $mime),
            function (StoredFileResponse $response) use ($invocationId, $file, $mime) {
                $this->events->dispatch(new FileStored(
                    $invocationId, $this, $file, $mime, $response,
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
