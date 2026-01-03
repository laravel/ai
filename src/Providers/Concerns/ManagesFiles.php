<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Files\StorableFile;
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
        if (Ai::filesAreFaked()) {
            Ai::recordFileUpload($file, $mime);
        }

        return $this->fileGateway()->putFile($this, $file, $mime);
    }

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(string $fileId): void
    {
        if (Ai::filesAreFaked()) {
            Ai::recordFileDeletion($fileId);
        }

        $this->fileGateway()->deleteFile($this, $fileId);
    }
}
