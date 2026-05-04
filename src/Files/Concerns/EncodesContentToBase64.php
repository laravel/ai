<?php

namespace Laravel\Ai\Files\Concerns;

trait EncodesContentToBase64
{
    abstract public function content(): string;

    public function base64(): string
    {
        return base64_encode($this->content());
    }
}
