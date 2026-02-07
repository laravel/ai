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
}
