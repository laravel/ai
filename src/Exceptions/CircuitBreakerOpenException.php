<?php

namespace Laravel\Ai\Exceptions;

class CircuitBreakerOpenException extends AiException implements FailoverableException
{
    public function __construct(string $provider)
    {
        parent::__construct("Circuit breaker is open for provider [{$provider}].");
    }
}
