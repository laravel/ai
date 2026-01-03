<?php

namespace Laravel\Ai\Responses;

class FileResponse
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $mime = null,
        public readonly ?string $content = null,
    ) {}

    /**
     * Get the MIME type for the file.
     */
    public function mimeType(): ?string
    {
        return $this->mime;
    }

    /**
     * Get the file's content.
     */
    public function content(): ?string
    {
        return $this->content;
    }
}
