<?php

namespace Laravel\Ai\Responses;

class FileResponse
{
    public readonly ?string $mime;

    public function __construct(
        public readonly string $id,
        ?string $mimeType = null,
        public readonly ?string $content = null,
    ) {
        $this->mime = $mimeType;
    }

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
