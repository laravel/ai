<?php

namespace Laravel\Ai\Gateway\Zai\Exceptions;

use RuntimeException;

class ModelNotSupportedException extends RuntimeException
{
    public static function forTools(string $model): self
    {
        return new self("Tool calling is only supported on GLM-4.6 and later models. Current model: {$model}.");
    }
}
