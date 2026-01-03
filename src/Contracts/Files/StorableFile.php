<?php

namespace Laravel\Ai\Contracts\Files;

use Stringable;

interface StorableFile extends Stringable
{
    public function storableContent(): string;

    public function storableMimeType(): ?string;
}
