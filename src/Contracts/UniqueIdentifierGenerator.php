<?php

namespace Laravel\Ai\Contracts;

/**
 * Contract for generating unique identifiers.
 */
interface UniqueIdentifierGenerator
{
    /**
     * Generate a unique identifier string.
     */
    public function generate(): string;

    /**
     * The length of identifiers produced by this generator.
     *
     * Used by migrations to size database columns appropriately.
     */
    public function length(): int;
}
