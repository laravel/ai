<?php

namespace Laravel\Ai\CodeMode;

use Closure;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\ToolNameResolver;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * Expose a tree of tools through a bounded orchestration DSL.
 */
class CodeMode
{
    /**
     * The tools exposed to programs, optionally grouped one level deep by namespace.
     *
     * @var array<int|string, mixed>
     */
    protected array $tools;

    protected int|float $timeout = 10;

    protected int $maxToolCalls = 25;

    protected int $maxOutputBytes = 65536;

    protected int $maxOperations = 10000;

    protected ?SerializableClosure $onToolCallStart = null;

    protected ?SerializableClosure $onToolCallEnd = null;

    /**
     * @param  iterable<int|string, mixed>  $tools
     */
    public function __construct(iterable $tools)
    {
        $this->tools = is_array($tools) ? $tools : iterator_to_array($tools);

        foreach ($this->tools as $key => $entry) {
            if (is_string($key) && is_iterable($entry) && ! is_array($entry)) {
                $entry = $this->tools[$key] = iterator_to_array($entry);
            }

            foreach (is_string($key) && is_iterable($entry) ? $entry : [$entry] as $leaf) {
                if ($leaf instanceof Approvable) {
                    throw new InvalidArgumentException(sprintf(
                        'Tool approvals are not supported inside code mode: [%s]. Pass this tool to the agent directly instead.',
                        $leaf instanceof Tool ? ToolNameResolver::resolve($leaf) : get_debug_type($leaf),
                    ));
                }
            }
        }
    }

    /**
     * Create a new code mode wrapper over the given tools, string keys becoming namespaces.
     *
     * @param  iterable<int|string, mixed>  $tools
     */
    public static function for(iterable $tools): self
    {
        return new self($tools);
    }

    /**
     * Limit a program's execution deadline, in seconds.
     */
    public function timeout(int|float $seconds): self
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('The code mode timeout must be greater than zero.');
        }

        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Limit how many tool calls a single program may make.
     */
    public function maxToolCalls(int $calls): self
    {
        if ($calls < 0) {
            throw new InvalidArgumentException('The code mode tool call limit must not be negative.');
        }

        $this->maxToolCalls = $calls;

        return $this;
    }

    /**
     * Limit how many evaluator operations a single program may perform.
     */
    public function maxOperations(int $operations): self
    {
        if ($operations < 1) {
            throw new InvalidArgumentException('The code mode operation limit must be at least one.');
        }

        $this->maxOperations = $operations;

        return $this;
    }

    /**
     * Limit the byte size of the model-facing result, truncating oversized values and logs.
     */
    public function maxOutputBytes(int $bytes): self
    {
        if ($bytes < 256) {
            throw new InvalidArgumentException('The code mode output byte limit must be at least 256 bytes.');
        }

        $this->maxOutputBytes = $bytes;

        return $this;
    }

    /**
     * Register a hook fired before each nested tool call with ['index', 'name', 'input'].
     */
    public function onToolCallStart(Closure $callback): self
    {
        $this->onToolCallStart = new SerializableClosure($callback);

        return $this;
    }

    /**
     * Register a hook fired after each nested tool call settles.
     */
    public function onToolCallEnd(Closure $callback): self
    {
        $this->onToolCallEnd = new SerializableClosure($callback);

        return $this;
    }

    /**
     * Expand into the execute_code tool, plus search_tools when the catalog is too large to inline.
     *
     * @param  Closure(mixed): mixed  $resolver
     * @param  Closure(Tool, string, array<string, mixed>, string): string|null  $toolInvoker
     * @return array<int, Tool>
     */
    public function expand(Closure $resolver, ?Closure $toolInvoker = null): array
    {
        $catalog = [];

        foreach ($this->tools as $key => $entry) {
            $namespace = is_string($key) ? $key : null;

            if ($namespace !== null && preg_match('/^[A-Za-z0-9_-]+$/', $namespace) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'A code mode namespace may only contain letters, numbers, underscores, and dashes: "%s" given.', $namespace
                ));
            }

            foreach (is_iterable($entry) && $namespace !== null ? $entry : [$entry] as $leaf) {
                $tool = $resolver($leaf);

                if (! $tool instanceof Tool) {
                    throw new InvalidArgumentException(sprintf(
                        'Only tools may be placed in a code mode tree: [%s] given. Pass it to the agent directly instead.',
                        get_debug_type($leaf),
                    ));
                }

                if ($leaf instanceof Approvable || $tool instanceof Approvable) {
                    throw new InvalidArgumentException(sprintf(
                        'Tool approvals are not supported inside code mode: [%s]. Pass this tool to the agent directly instead.',
                        ToolNameResolver::resolve($tool),
                    ));
                }

                $path = ($namespace !== null ? $namespace.'.' : '').ToolNameResolver::resolve($tool);

                if (isset($catalog[$path])) {
                    throw new InvalidArgumentException(sprintf(
                        'Multiple tools resolve to the path [%s] in this code mode tree. Namespace or rename them.', $path
                    ));
                }

                $catalog[$path] = $tool;
            }
        }

        $catalog = new Catalog($catalog);

        $tools = [new ExecuteCode(
            $catalog,
            $this->timeout,
            $this->maxToolCalls,
            $this->maxOutputBytes,
            $this->maxOperations,
            $toolInvoker,
            $this->onToolCallStart?->getClosure(),
            $this->onToolCallEnd?->getClosure(),
        )];

        // A search tool only earns its slot when the catalog is too large to inline.
        return $catalog->isPartial() ? [...$tools, new SearchTools($catalog)] : $tools;
    }
}
