<?php

namespace Laravel\Ai\Files\Concerns;

trait EncodesContentToBase64
{
    abstract public function content(): string;

    public function asEncoded(): string
    {
        return base64_encode($this->content());
    }
}
