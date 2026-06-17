<?php

namespace Laravel\Ai\Gateway\Concerns;

use Laravel\Ai\Contracts\Files\StorableFile;

trait PreparesStorableFiles
{
    /**
     * Prepare file data for upload.
     *
     * @return array{string, string, string}
     */
    protected function prepareStorableFile(StorableFile $file): array
    {
        return [
            $file->content(),
            $file->mimeType() ?? 'application/octet-stream',
            $file->name() ?? 'file',
        ];
    }
}
