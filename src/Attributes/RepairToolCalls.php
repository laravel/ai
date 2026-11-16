<?php

namespace Laravel\Ai\Attributes;

use Attribute;
use ReflectionClass;

#[Attribute(Attribute::TARGET_CLASS)]
final class RepairToolCalls
{
    /**
     * Determine if the target repairs tool calls, through its repairToolCalls method or the attribute.
     */
    public static function isAppliedTo(?object $target): bool
    {
        if ($target === null) {
            return false;
        }

        if (method_exists($target, 'repairToolCalls')) {
            return (bool) $target->repairToolCalls();
        }

        return (new ReflectionClass($target))->getAttributes(self::class) !== [];
    }
}
