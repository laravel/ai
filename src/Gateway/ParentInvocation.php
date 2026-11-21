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
     * The context of the run the current run was delegated from.
     */
    protected static ?RunContext $context = null;

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
     * Get the context of the run the current run was delegated from.
     *
     * @internal
     */
    public static function context(): ?RunContext
    {
        return static::$context;
    }

    /**
     * Run the given callback with the given invocation and tool invocation as the delegating parent.
     */
    public static function within(?string $invocationId, ?string $toolInvocationId, Closure $callback, ?RunContext $context = null): mixed
    {
        $previous = [static::$current, static::$context];

        static::$current = [$invocationId, $toolInvocationId];
        static::$context = $context;

        try {
            return $callback();
        } finally {
            [static::$current, static::$context] = $previous;
        }
    }
}
