<?php

namespace Laravel\Ai\CodeMode;

use Closure;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * Expose a tree of tools to the model as a single execute_code tool it drives with PHP programs.
 */
class CodeMode
{
    /**
     * The tools exposed to programs, optionally grouped one level deep by namespace.
     *
     * @var array<int|string, mixed>
     */
    protected array $tools;

    protected int|float|null $timeout = null;

    protected ?int $maxToolCalls = null;

    protected ?int $maxOutputBytes = null;

    protected ?Closure $onToolCallStart = null;

    protected ?Closure $onToolCallEnd = null;

    /**
     * @param  iterable<int|string, mixed>  $tools
     */
    public function __construct(iterable $tools)
    {
        $this->tools = is_array($tools) ? $tools : iterator_to_array($tools);
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
     * Limit a program's wall-clock execution time, in seconds.
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
     * Limit the byte size of the model-facing result, truncating oversized values and logs.
     */
    public function maxOutputBytes(int $bytes): self
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException('The code mode output byte limit must not be negative.');
        }

        $this->maxOutputBytes = $bytes;

        return $this;
    }

    /**
     * Register a hook fired before each nested tool call with ['index', 'name', 'input'].
     */
    public function onToolCallStart(Closure $callback): self
    {
        $this->onToolCallStart = $callback;

        return $this;
    }

    /**
     * Register a hook fired after each nested tool call settles.
     */
    public function onToolCallEnd(Closure $callback): self
    {
        $this->onToolCallEnd = $callback;

        return $this;
    }

    /**
     * Expand into the execute_code tool, plus search_tools when the catalog is too large to inline.
     *
     * @param  Closure(mixed): mixed  $resolver
     * @return array<int, Tool>
     */
    public function expand(Closure $resolver): array
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
            $this->onToolCallStart,
            $this->onToolCallEnd,
        )];

        // A search tool only earns its slot when the catalog is too large to inline.
        return $catalog->isPartial() ? [...$tools, new SearchTools($catalog)] : $tools;
    }
}
