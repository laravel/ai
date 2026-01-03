<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Files\StorableFile;

trait PreparesStorableFiles
{
    /**
     * Prepare file data for upload.
     *
     * @return array{string, string, string}
     */
    protected function prepareStorableFile(StorableFile|UploadedFile|string $file, ?string $mime): array
    {
        return match (true) {
            $file instanceof StorableFile => [
                $file->storableContent(),
                $mime ?? $file->storableMimeType() ?? 'application/octet-stream',
                'file',
            ],
            $file instanceof UploadedFile => [
                $file->getContent(),
                $mime ?? $file->getClientMimeType() ?? 'application/octet-stream',
                $file->getClientOriginalName(),
            ],
            default => [
                $file,
                $mime ?? 'application/octet-stream',
                'file',
            ],
        };
    }
}
