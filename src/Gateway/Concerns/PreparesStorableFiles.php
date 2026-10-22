<?php

namespace Laravel\Ai\Gateway\Concerns;

use Closure;
use Laravel\Ai\Contracts\Files\HasStream;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\File;

trait PreparesStorableFiles
{
    /**
     * Prepare file data for upload.
     *
     * @return array{resource|string, string, string}
     */
    protected function prepareStorableFile(StorableFile $file): array
    {
        $mime = $file->mimeType() ?? 'application/octet-stream';
        $name = $file->name() ?? 'file';

        return [
            $file instanceof HasStream ? $file->stream() : $file->content(),
            $mime,
            $name,
        ];
    }

    /**
     * Open and close any file stream around a single HTTP upload attempt.
     *
     * The surrounding retry handler calls this again for every attempt.
     */
    protected function withPreparedStorableFile(StorableFile $file, Closure $callback): mixed
    {
        [$content, $mime, $name] = $this->prepareStorableFile($file);

        try {
            return $callback($content, $mime, $name);
        } finally {
            if (is_resource($content)) {
                fclose($content);
            }
        }
    }

    /**
     * Resolve the upload body options and HTTP headers for the given file.
     *
     * @return array{array<string, mixed>, array<string, string>}
     */
    protected function resolveProviderOptionsAndHeaders(StorableFile $file, Lab|string $provider): array
    {
        return [
            $file instanceof HasProviderOptions ? $file->providerOptions($provider) : [],
            $file instanceof File ? $file->headers($provider) : [],
        ];
    }
}
