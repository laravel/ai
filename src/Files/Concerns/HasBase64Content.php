<?php

namespace Laravel\Ai\Files\Concerns;

trait HasBase64Content
{
    public function base64(): string
    {
        return $this->base64;
    }
}
