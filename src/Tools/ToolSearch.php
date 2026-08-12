<?php

namespace Laravel\Ai\Tools;

use Closure;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;

/**
 * Expose a catalog of tools through on-demand search and execution.
 */
class ToolSearch
{
    /**
     * The catalog of tools the model can discover and run on demand.
     *
     * @var array<int, mixed>
     */
    protected array $tools;

    protected int $maxToolCalls = 25;

    protected int $maxOutputBytes = 65536;

    /**
     * @param  iterable<int, mixed>  $tools
     */
    public function __construct(iterable $tools)
    {
        $this->tools = is_array($tools) ? array_values($tools) : iterator_to_array($tools, false);

        foreach ($this->tools as $tool) {
            if ($tool instanceof Approvable) {
                throw new InvalidArgumentException(sprintf(
                    'Tool approvals are not supported inside tool search: [%s]. Pass this tool to the agent directly instead.',
                    $tool instanceof Tool ? ToolNameResolver::resolve($tool) : get_debug_type($tool),
                ));
            }
        }
    }

    /**
     * Create a new tool search over the given catalog of tools.
     *
     * @param  iterable<int, mixed>  $tools
     */
    public static function for(iterable $tools): self
    {
        return new self($tools);
    }

    /**
     * Limit how many tool calls a single execute_tools invocation may make.
     */
    public function maxToolCalls(int $calls): self
    {
        if ($calls < 1) {
            throw new InvalidArgumentException('The tool search call limit must be at least one.');
        }

        $this->maxToolCalls = $calls;

        return $this;
    }

    /**
     * Limit the byte size of each model-facing result.
     */
    public function maxOutputBytes(int $bytes): self
    {
        if ($bytes < 256) {
            throw new InvalidArgumentException('The tool search output byte limit must be at least 256 bytes.');
        }

        $this->maxOutputBytes = $bytes;

        return $this;
    }

    /**
     * Expand into the pair of meta-tools the model interacts with: search_tools and execute_tools.
     *
     * @param  Closure(mixed): mixed  $resolver
     * @param  Closure(Tool, string, array<string, mixed>, string): string|null  $toolInvoker
     * @return array<int, Tool>
     */
    public function expand(Closure $resolver, ?Closure $toolInvoker = null): array
    {
        $catalog = [];

        foreach ($this->tools as $entry) {
            $tool = $resolver($entry);

            if (! $tool instanceof Tool) {
                throw new InvalidArgumentException(sprintf(
                    'Only tools may be placed in a tool search catalog: [%s] given. Pass it to the agent directly instead.',
                    get_debug_type($entry),
                ));
            }

            if ($entry instanceof Approvable || $tool instanceof Approvable) {
                throw new InvalidArgumentException(sprintf(
                    'Tool approvals are not supported inside tool search: [%s]. Pass this tool to the agent directly instead.',
                    ToolNameResolver::resolve($tool),
                ));
            }

            $name = ToolNameResolver::resolve($tool);

            if (isset($catalog[$name])) {
                throw new InvalidArgumentException(sprintf(
                    'Multiple tools resolve to the name [%s] in this tool search catalog. Rename them.', $name
                ));
            }

            $catalog[$name] = $tool;
        }

        $catalog = new ToolCatalog($catalog);

        return [
            new SearchTools($catalog),
            new ExecuteTools($catalog, $this->maxToolCalls, $this->maxOutputBytes, $toolInvoker),
        ];
    }
}
