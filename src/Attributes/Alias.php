<?php

namespace Laravel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Alias
{
    public function __construct(public string $value)
    {
        //
    }
}
