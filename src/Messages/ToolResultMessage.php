<?php

namespace Laravel\Ai\Messages;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\ToolResult;

class ToolResultMessage extends Message
{
    /** @var Collection<int, ToolResult> */
    public Collection $toolResults;

    /**
     * Create a new text conversation message instance.
     *
     * @param  Collection<int, ToolResult>  $toolResults
     */
    public function __construct(Collection $toolResults)
    {
        parent::__construct('tool_result', content: null);

        $this->toolResults = $toolResults;
    }
}
