<?php

namespace Laravel\Ai\Gateway\Concerns;

use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

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

    /**
     * Resolve the provider-specific upload options for the given file.
     *
     * @return array<string, mixed>
     */
    protected function resolveProviderOptions(StorableFile $file, Lab|string $provider): array
    {
        return $file instanceof HasProviderOptions ? $file->providerOptions($provider) : [];
    }
}
