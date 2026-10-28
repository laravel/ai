<?php

namespace Laravel\Ai\Exceptions;

class NoStructuredOutputException extends AiException implements FailoverableException
{
    public function __construct(public readonly string $text)
    {
        parent::__construct('The model did not return valid structured output.');
    }
}
