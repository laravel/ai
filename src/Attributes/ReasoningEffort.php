<?php

namespace Laravel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class ReasoningEffort
{
    public function __construct(public string $value = 'medium')
    {
        //
    }
}
