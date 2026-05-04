<?php

namespace Laravel\Ai\Contracts\Files;

interface EmbeddableFile
{
    /**
     * Get the Base64 encoded representation of the file.
     */
    public function base64(): string;

    /**
     * Get the file's MIME type with a safe inline fallback.
     */
    public function resolvedMimeType(): string;

    /**
     * Get the file as a data URI.
     */
    public function asDataUri(): string;
}
