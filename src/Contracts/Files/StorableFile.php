<?php

namespace Laravel\Ai\Contracts\Files;

interface StorableFile
{
    public function storableContent(): string;

    public function storableMimeType(): ?string;
}
