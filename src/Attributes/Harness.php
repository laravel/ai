<?php

namespace Laravel\Ai\Attributes;

use Attribute;
use Laravel\Ai\Enums\Harness as HarnessName;

#[Attribute(Attribute::TARGET_CLASS)]
class Harness
{
    public function __construct(public HarnessName|string $value = HarnessName::ClaudeCode) {}
}
