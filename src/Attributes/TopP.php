<?php

namespace Laravel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class TopP
{
    public function __construct(public float $value)
    {
        //
    }
}
