<?php

namespace Laravel\Ai\Support\Generators;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\UniqueIdentifierGenerator;

/**
 * UUID v7 generator.
 */
class UuidV7Generator implements UniqueIdentifierGenerator
{
    public function generate(): string
    {
        return (string) Str::uuid7();
    }
}
