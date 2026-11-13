<?php

namespace Laravel\Ai\Exceptions;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

class InvalidApiKeyException extends RequestException
{
    public function __construct(public string $provider, Response $response, $truncateExceptionsAt = null)
    {
        parent::__construct($response, $truncateExceptionsAt);
    }

    protected function prepareMessage(Response $response): string
    {
        return "Missing or invalid API key for AI provider [{$this->provider}]. ".parent::prepareMessage($response);
    }
}
