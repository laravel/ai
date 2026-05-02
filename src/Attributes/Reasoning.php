<?php

namespace Laravel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Reasoning
{
    public function __construct(public bool $enabled = true)
    {
        //
    }
}
