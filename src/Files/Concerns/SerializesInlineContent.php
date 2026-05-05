<?php

namespace Laravel\Ai\Files\Concerns;

trait SerializesInlineContent
{
    abstract public function asEncoded(): string;

    abstract public function mimeType(): string;

    public function asDataUri(): string
    {
        return 'data:'.$this->mimeType().';base64,'.$this->asEncoded();
    }
}
