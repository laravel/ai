<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Illuminate\Support\Str;

trait ResolvesFileUris
{
    /**
     * Resolve the file URI that Gemini content blocks reference from a file ID, name, or URI.
     */
    protected function fileUri(string $file): string
    {
        return str_contains($file, '://')
            ? $file
            : 'https://generativelanguage.googleapis.com/v1beta/files/'.$this->fileId($file);
    }

    /**
     * Resolve the bare Files API ID from a file ID, name, or URI.
     */
    protected function fileId(string $file): string
    {
        return Str::chopStart($file, ['https://generativelanguage.googleapis.com/v1beta/files/', 'files/']);
    }
}
