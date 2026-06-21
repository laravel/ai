<?php

namespace Laravel\Ai\Exceptions;

class NoSuchToolException extends AiException
{
    public function __construct(public readonly string $toolName)
    {
        parent::__construct(sprintf("Model tried to call unavailable tool '%s'.", $toolName));
    }
}
