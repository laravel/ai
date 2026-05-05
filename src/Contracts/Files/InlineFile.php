<?php

namespace Laravel\Ai\Contracts\Files;

interface InlineFile
{
    /**
     * Get the Base64 encoded representation of the file.
     */
    public function asEncoded(): string;

    /**
     * Get the file's MIME type with a safe inline fallback.
     */
    public function mimeType(): string;

    /**
     * Get the file as a data URI.
     */
    public function asDataUri(): string;
}
