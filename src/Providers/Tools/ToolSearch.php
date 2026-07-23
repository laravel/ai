<?php

namespace Laravel\Ai\Providers\Tools;

use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;

class ToolSearch extends ProviderTool
{
    /**
     * @param  array<Tool>  $tools
     */
    public function __construct(public array $tools = [], public ?string $strategy = null)
    {
        if ($strategy !== null && ! in_array($strategy, ['regex', 'bm25'], true)) {
            throw new InvalidArgumentException(
                "Invalid tool search strategy [{$strategy}]. Supported strategies: regex, bm25."
            );
        }
    }

    /**
     * Set the deferred tools discovered through search.
     *
     * @param  array<Tool>  $tools
     */
    public function withTools(array $tools): self
    {
        $this->tools = $tools;

        return $this;
    }

    /**
     * Count the given tools for step budgeting, expanding each ToolSearch into its deferred tools.
     *
     * @param  array<mixed>  $tools
     */
    public static function budget(array $tools): int
    {
        return array_sum(array_map(
            fn ($tool) => $tool instanceof self ? count($tool->tools) : 1,
            $tools,
        ));
    }
}
