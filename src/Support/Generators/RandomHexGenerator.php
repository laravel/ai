<?php

namespace Laravel\Ai\Support\Generators;

use Laravel\Ai\Contracts\UniqueIdentifierGenerator;

/**
 * Random hex generator (32 characters).
 */
class RandomHexGenerator implements UniqueIdentifierGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
