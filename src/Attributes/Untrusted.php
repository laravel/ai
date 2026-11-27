<?php

namespace Laravel\Ai\Attributes;

use Attribute;
use ReflectionClass;

/**
 * Mark a tool whose results carry content the application doesn't control, such as web pages, emails, or
 * documents. The result is wrapped as untrusted content before the model sees it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Untrusted
{
    public function __construct(public ?string $source = null) {}

    /**
     * Get the attribute applied to the given target, if any.
     */
    public static function on(?object $target): ?self
    {
        if ($target === null) {
            return null;
        }

        $attributes = (new ReflectionClass($target))->getAttributes(self::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}
