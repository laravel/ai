<?php

namespace Laravel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Bind
{
    public function __construct(public ?string $name = null)
    {
        //
    }
}
