<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Log\Context\Repository as ContextRepository;

/** A tool handler is arbitrary user code, so the delegating pair travels in hidden context rather than as a parameter. */
class ParentInvocation
{
    protected const CONTEXT_KEY = 'laravel-ai.parent-invocation';

    /**
     * Get the invocation and tool invocation the current run was delegated from.
     *
     * @return array{?string, ?string}
     */
    public static function current(): array
    {
        return static::context()?->getHidden(static::CONTEXT_KEY) ?? [null, null];
    }

    /**
     * Run the given callback with the given invocation and tool invocation as the delegating parent.
     */
    public static function within(?string $invocationId, string $toolInvocationId, Closure $callback): mixed
    {
        $context = static::context();

        if ($context === null) {
            return $callback();
        }

        $previous = $context->getHidden(static::CONTEXT_KEY);

        $context->addHidden(static::CONTEXT_KEY, [$invocationId, $toolInvocationId]);

        try {
            return $callback();
        } finally {
            $previous === null
                ? $context->forgetHidden(static::CONTEXT_KEY)
                : $context->addHidden(static::CONTEXT_KEY, $previous);
        }
    }

    /**
     * Resolve the context repository, if the application provides one.
     */
    protected static function context(): ?ContextRepository
    {
        $container = Container::getInstance();

        return $container->bound(ContextRepository::class)
            ? $container->make(ContextRepository::class)
            : null;
    }
}
