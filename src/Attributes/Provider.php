<?php

namespace Laravel\Ai\Attributes;

use Attribute;
use Laravel\Ai\Enums\Lab;

#[Attribute(Attribute::TARGET_CLASS)]
class Provider
{
    public function __construct(public Lab|array|string $value)
    {
        //
    }
}
