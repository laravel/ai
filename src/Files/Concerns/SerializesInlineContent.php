<?php

namespace Laravel\Ai\Files\Concerns;

trait SerializesInlineContent
{
    abstract public function base64(): string;

    abstract public function mimeType(): ?string;

    public function resolvedMimeType(): string
    {
        return $this->mimeType() ?? static::DEFAULT_INLINE_MIME_TYPE;
    }

    public function asDataUri(): string
    {
        return 'data:'.$this->resolvedMimeType().';base64,'.$this->base64();
    }
}
