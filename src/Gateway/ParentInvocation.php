<?php

namespace Laravel\Ai\Gateway;

use Closure;

class ParentInvocation
{
    /**
     * The invocation and tool invocation the current run was delegated from.
     *
     * @var array{?string, ?string}
     */
    protected static array $current = [null, null];

    /**
     * Get the invocation and tool invocation the current run was delegated from.
     *
     * @return array{?string, ?string}
     */
    public static function current(): array
    {
        return static::$current;
    }

    /**
     * Run the given callback with the given invocation and tool invocation as the delegating parent.
     */
    public static function within(?string $invocationId, string $toolInvocationId, Closure $callback): mixed
    {
        $previous = static::$current;

        static::$current = [$invocationId, $toolInvocationId];

        try {
            return $callback();
        } finally {
            static::$current = $previous;
        }
    }
}
