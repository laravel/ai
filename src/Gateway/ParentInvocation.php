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

        // A tool handler is arbitrary user code, so the delegating pair waits here rather than travelling as a parameter...
        static::$current = [$invocationId, $toolInvocationId];

        try {
            return $callback();
        } finally {
            static::$current = $previous;
        }
    }

    /**
     * Forget the delegating pair.
     *
     * The pair is process global and unwinds on its own, so this only matters where a fatal could
     * strand it in a reused process, such as an Octane worker or between tests.
     */
    public static function flush(): void
    {
        static::$current = [null, null];
    }
}
