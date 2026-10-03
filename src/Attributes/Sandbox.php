<?php

namespace Laravel\Ai\Attributes;

use Attribute;
use ReflectionClass;

#[Attribute(Attribute::TARGET_CLASS)]
class Sandbox
{
    public function __construct(
        public ?string $name = null,
        public ?string $cwd = null,
        public bool $approveCommands = true,
    ) {
        //
    }

    /**
     * Get the sandbox attribute declared on the given agent, if any.
     */
    public static function on(object $target): ?self
    {
        return ((new ReflectionClass($target))->getAttributes(self::class)[0] ?? null)?->newInstance();
    }
}
