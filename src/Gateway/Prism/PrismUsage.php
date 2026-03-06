<?php

namespace Laravel\Ai\Gateway\Prism;

use Laravel\Ai\Responses\Data\Usage;
use Prism\Prism\ValueObjects\Usage as PrismUsageValueObject;

class PrismUsage
{
    /**
     * Convert the Prism usage value object to a Laravel AI SDK usage object.
     */
    public static function toLaravelUsage(?PrismUsageValueObject $usage): Usage
    {
        return new Usage(
            $usage?->promptTokens ?: 0,
            $usage?->completionTokens ?: 0,
            $usage?->cacheWriteInputTokens ?: 0,
            $usage?->cacheReadInputTokens ?: 0,
            $usage?->thoughtTokens ?: 0,
        );
    }
}
