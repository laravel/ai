<?php

namespace Laravel\Ai\Providers\Tools;

use Laravel\Ai\Contracts\Tool;

class ProviderToolSearch extends ProviderTool
{
    /**
     * @param  array<Tool>  $tools
     */
    public function __construct(public array $tools = [])
    {
        //
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
     * Count the given tools for step budgeting, expanding each ProviderToolSearch into its deferred tool count.
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
