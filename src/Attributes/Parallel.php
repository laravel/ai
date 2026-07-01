<?php

namespace Laravel\Ai\Attributes;

use Attribute;
use ReflectionMethod;

#[Attribute(Attribute::TARGET_METHOD)]
final class Parallel
{
    public static function isAppliedTo(?object $target): bool
    {
        return $target !== null
            && method_exists($target, 'tools')
            && (new ReflectionMethod($target, 'tools'))->getAttributes(self::class) !== [];
    }
}
