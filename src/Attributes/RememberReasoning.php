<?php

namespace Laravel\Ai\Attributes;

use Attribute;
use ReflectionClass;

/**
 * Keep the provider's signed reasoning of completed turns, so follow-up turns replay it verbatim.
 *
 * Anthropic only: it keeps the prompt cache warm across turns and passes earlier thinking back, which
 * requires the agent's instructions and tools to stay the same for the whole conversation.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class RememberReasoning
{
    public static function isAppliedTo(?object $target): bool
    {
        return $target !== null
            && (new ReflectionClass($target))->getAttributes(self::class) !== [];
    }
}
