<?php

namespace Laravel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class CacheConversation
{
    public function __construct(public ?string $ttl = null)
    {
        //
    }
}
