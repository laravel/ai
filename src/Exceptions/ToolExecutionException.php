<?php

namespace Laravel\Ai\Exceptions;

use Throwable;

/**
 * @internal
 */
class ToolExecutionException extends AiException
{
    public function __construct(public readonly Throwable $exception)
    {
        parent::__construct($exception->getMessage(), previous: $exception);
    }
}
