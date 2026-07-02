<?php

namespace Laravel\Ai\Exceptions;

class StructuredOutputValidationException extends AiException
{
    /**
     * @param  array<int, string>  $violations
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly array $violations, public readonly array $data)
    {
        parent::__construct(
            "Structured output does not satisfy the schema's value constraints: ".implode(' ', $violations)
        );
    }
}
