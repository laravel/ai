<?php

namespace Laravel\Ai\Files\Concerns;

trait HasStoredBase64
{
    public function base64(): string
    {
        return $this->base64;
    }
}
