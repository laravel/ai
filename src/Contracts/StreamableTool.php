<?php

namespace Laravel\Ai\Contracts;

use Generator;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Tools\Request;

interface StreamableTool extends Tool
{
    /**
     * Stream the tool's activity and return its final tool result.
     *
     * @return Generator<int, StreamEvent, mixed, string>
     */
    public function streamHandle(Request $request): Generator;
}
