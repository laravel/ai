<?php

namespace Laravel\Ai\Responses;

class FileResponse
{
    public readonly ?string $mime;

    public function __construct(
        public readonly string $id,
        ?string $mimeType = null,
        public readonly ?string $content = null,
        public readonly ?string $uri = null,
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

    /**
     * Get the provider URI for the file.
     */
    public function uri(): ?string
    {
        return $this->uri;
    }
}
