<?php

namespace Laravel\Ai\Attributes;

use Attribute;
use Laravel\Ai\Middleware\Stop;
use Laravel\Ai\PendingStep;
use ReflectionClass;

#[Attribute(Attribute::TARGET_CLASS)]
final class StopWhen
{
    /**
     * @param  string  $method  The agent method deciding, given the PendingStep, whether to stop.
     */
    public function __construct(public string $method)
    {
        //
    }

    /**
     * @return array<int, Stop>
     */
    public static function middlewareFor(?object $agent): array
    {
        if ($agent === null) {
            return [];
        }

        $attributes = (new ReflectionClass($agent))->getAttributes(self::class);

        if ($attributes === []) {
            return [];
        }

        $method = $attributes[0]->newInstance()->method;

        return [Stop::when(fn (PendingStep $step): bool => $agent->{$method}($step))];
    }
}
