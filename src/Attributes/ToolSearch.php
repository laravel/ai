<?php

namespace Laravel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class ToolSearch
{
    public function __construct(public string $strategy = 'regex')
    {
        //
    }
}
