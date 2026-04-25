<?php

namespace Laravel\Ai\Files\Concerns;

trait HasBinaryContent
{
    public function base64(): string
    {
        return base64_encode($this->content());
    }

    public function resolvedMimeType(): string
    {
        return $this->mimeType() ?? $this->defaultMimeType();
    }

    public function asDataUri(): string
    {
        return 'data:'.$this->resolvedMimeType().';base64,'.$this->base64();
    }
}
