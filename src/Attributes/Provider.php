<?php

namespace Laravel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Provider
{
    public function __construct(public array|string $value)
    {
        //
    }
}
