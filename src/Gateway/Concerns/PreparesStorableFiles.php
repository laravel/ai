<?php

namespace Laravel\Ai\Gateway\Concerns;

use Closure;
use Laravel\Ai\Contracts\Files\HasStream;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\File;
use Psr\Http\Message\StreamInterface;

trait PreparesStorableFiles
{
    /**
     * Open the file's content, MIME type, and name for a single upload attempt.
     *
     * @param  Closure(StreamInterface|string, string, string): mixed  $callback
     */
    protected function withPreparedStorableFile(StorableFile $file, Closure $callback): mixed
    {
        $content = $file instanceof HasStream ? $file->stream() : $file->content();

        try {
            return $callback($content, $file->mimeType() ?? 'application/octet-stream', $file->name() ?? 'file');
        } finally {
            if ($content instanceof StreamInterface) {
                $content->close();
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
