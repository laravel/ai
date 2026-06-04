<?php

namespace Laravel\Ai;

use Closure;

class InvocationContext
{
    /**
     * The stack of active invocation contexts for the current execution.
     *
     * @var array<int, self>
     */
    protected static array $active = [];

    public function __construct(
        public readonly string $id,
        public readonly ?string $parentId = null,
        public readonly ?string $rootId = null,
    ) {}

    /**
     * Create a new root invocation context.
     */
    public static function root(string $id): self
    {
        return new self($id, null, $id);
    }

    /**
     * Create an invocation context for the given id, nesting it beneath the
     * currently active context when one is present.
     */
    public static function for(string $id): self
    {
        $parent = static::current();

        if ($parent === null) {
            return static::root($id);
        }

        return new self($id, $parent->id, $parent->rootId);
    }

    /**
     * Reconstruct a context from ids carried across a boundary (e.g. a queued
     * job) so a child can nest beneath it. The source context's own parent is
     * not tracked - for() only needs its id and root.
     */
    public static function rehydrate(string $id, ?string $rootId = null): self
    {
        return new self($id, null, $rootId ?? $id);
    }

    /**
     * Get the currently active invocation context, if any.
     */
    public static function current(): ?self
    {
        return static::$active === []
            ? null
            : static::$active[array_key_last(static::$active)];
    }

    /**
     * Run the given callback with the context active, so any agent invoked
     * within it (such as a sub-agent used as a tool) nests beneath it.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(self $context, Closure $callback): mixed
    {
        static::push($context);

        try {
            return $callback();
        } finally {
            static::pop();
        }
    }

    /**
     * Push the context onto the active stack. Pair with pop() in a finally
     * block; prefer run() unless the work is a lazily-consumed generator
     * (streaming) that cannot be wrapped in a single callback.
     */
    public static function push(self $context): void
    {
        static::$active[] = $context;
    }

    /**
     * Deactivate the most recently pushed context.
     */
    public static function pop(): void
    {
        array_pop(static::$active);
    }

    /**
     * Flush the active context stack.
     */
    public static function flush(): void
    {
        static::$active = [];
    }

    /**
     * Determine if the context is a top-level (root) invocation.
     */
    public function isRoot(): bool
    {
        return $this->parentId === null;
    }
}
