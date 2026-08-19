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
     * Resolve the provider-specific upload options for the given file.
     *
     * @return array<string, mixed>
     */
    protected function resolveProviderOptions(StorableFile $file, Lab|string $provider): array
    {
        return Arr::except($this->fileProviderOptions($file, $provider), HasProviderOptions::HEADERS);
    }

    /**
     * Resolve the HTTP headers the given file should be uploaded with.
     *
     * @return array<string, string>
     */
    protected function resolveRequestHeaders(StorableFile $file, Lab|string $provider): array
    {
        return $this->fileProviderOptions($file, $provider)[HasProviderOptions::HEADERS] ?? [];
    }

    /**
     * Resolve every provider option declared for the given file.
     *
     * @return array<string, mixed>
     */
    private function fileProviderOptions(StorableFile $file, Lab|string $provider): array
    {
        return $file instanceof HasProviderOptions ? $file->providerOptions($provider) : [];
    }
}
