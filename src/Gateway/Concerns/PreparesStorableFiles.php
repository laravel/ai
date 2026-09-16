<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\Support\Arr;
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
     * Resolve the upload body options and HTTP headers for the given file.
     *
     * @return array{array<string, mixed>, array<string, string>}
     */
    protected function resolveProviderOptionsAndHeaders(StorableFile $file, Lab|string $provider): array
    {
        $options = $file instanceof HasProviderOptions ? $file->providerOptions($provider) : [];

        return [
            Arr::except($options, HasProviderOptions::HEADERS),
            $options[HasProviderOptions::HEADERS] ?? [],
        ];
    }
}
