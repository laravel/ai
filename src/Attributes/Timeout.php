<?php

namespace Laravel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Timeout
{
    public function __construct(public int $value)
    {
        //
    }
}
