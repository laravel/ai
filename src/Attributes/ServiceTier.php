<?php

namespace Laravel\Ai\Attributes;

use Attribute;
use BackedEnum;

#[Attribute(Attribute::TARGET_CLASS)]
class ServiceTier
{
    public string $value;

    /**
     * Accepts a raw provider string (e.g. 'priority') or any provider service
     * tier enum, normalizing it down to its string value.
     */
    public function __construct(string|BackedEnum $value)
    {
        $this->value = $value instanceof BackedEnum ? (string) $value->value : $value;
    }
}
