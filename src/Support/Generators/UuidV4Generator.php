<?php

namespace Laravel\Ai\Support\Generators;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\UniqueIdentifierGenerator;

/**
 * UUID v4 generator.
 */
class UuidV4Generator implements UniqueIdentifierGenerator
{
    public function generate(): string
    {
        return (string) Str::uuid();
    }

    public function length(): int
    {
        return 36;
    }
}
