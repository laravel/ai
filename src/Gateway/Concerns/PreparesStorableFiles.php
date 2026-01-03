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
    protected function prepareStorableFile(StorableFile $file, ?string $mime, ?string $name): array
    {
        return match (true) {
            $file instanceof StorableFile => [
                $file->storableContent(),
                $mime ?? $file->storableMimeType() ?? 'application/octet-stream',
                $name ?? $file->storableName() ?? 'file',
            ],
        };
    }
}
