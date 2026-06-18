<?php

namespace Laravel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class MaxCost
{
    public function __construct(public float $value)
    {
        //
    }
}
