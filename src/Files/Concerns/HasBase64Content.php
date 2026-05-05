<?php

namespace Laravel\Ai\Files\Concerns;

trait HasBase64Content
{
    public function asEncoded(): string
    {
        return $this->base64;
    }
}
