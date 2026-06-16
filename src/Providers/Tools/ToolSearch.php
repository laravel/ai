<?php

namespace Laravel\Ai\Providers\Tools;

use Laravel\Ai\Contracts\Tool;

class ToolSearch extends ProviderTool
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
}
