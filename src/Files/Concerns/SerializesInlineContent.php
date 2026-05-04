<?php

namespace Laravel\Ai\Files\Concerns;

trait SerializesInlineContent
{
    abstract public function base64(): string;

    abstract public function mimeType(): ?string;

    abstract protected function defaultMimeType(): string;

    public function resolvedMimeType(): string
    {
        return $this->mimeType() ?? $this->defaultMimeType();
    }

    public function asDataUri(): string
    {
        return 'data:'.$this->resolvedMimeType().';base64,'.$this->base64();
    }
}
