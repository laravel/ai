<?php

namespace Laravel\Ai\Exceptions;

use Laravel\Ai\Contracts\Tool;
use RuntimeException;

class ToolApprovalRequiredException extends RuntimeException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(
        public readonly Tool $tool,
        public readonly array $arguments,
    ) {
        $toolName = method_exists($tool, 'name')
            ? $tool->name()
            : class_basename($tool);

        parent::__construct("Tool [{$toolName}] requires approval before execution.");
    }
}
