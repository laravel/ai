<?php

namespace Laravel\Ai\Files;

interface StorableFile
{
    public function storableContent(): string;

    public function storableMimeType(): ?string;
}
