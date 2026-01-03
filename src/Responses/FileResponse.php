<?php

namespace Laravel\Ai\Responses;

class FileResponse
{
    public function __construct(
        public string $id,
        public ?string $mime = null,
        public ?string $content = null,
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
