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
    protected static ?RunContext $runContext = null;

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
     */
    public static function runContext(): ?RunContext
    {
        return static::$runContext;
    }

    /**
     * Run the given callback with the given invocation and tool invocation as the delegating parent.
     */
    public static function within(?string $invocationId, string $toolInvocationId, Closure $callback, ?RunContext $runContext = null): mixed
    {
        $previous = [static::$current, static::$runContext];

        static::$current = [$invocationId, $toolInvocationId];
        static::$runContext = $runContext;

        try {
            return $callback();
        } finally {
            [static::$current, static::$runContext] = $previous;
        }
    }
}
