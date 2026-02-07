<?php

namespace Laravel\Ai\Support\Generators;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\UniqueIdentifierGenerator;

/**
 * ULID generator.
 */
class UlidGenerator implements UniqueIdentifierGenerator
{
    public function generate(): string
    {
        return strtolower((string) Str::ulid());
    }
}
