<?php

namespace Laravel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Thinking
{
    public function __construct(public bool $enabled = true, public ?int $budgetTokens = null, public ?string $effort = null)
    {
        //
    }
}
