<?php

namespace Laravel\Ai\Attributes;

use Attribute;
use ReflectionClass;

#[Attribute(Attribute::TARGET_CLASS)]
final class ExecuteToolsEagerly
{
    public static function isAppliedTo(?object $target): bool
    {
        return $target !== null
            && (new ReflectionClass($target))->getAttributes(self::class) !== [];
    }
}
