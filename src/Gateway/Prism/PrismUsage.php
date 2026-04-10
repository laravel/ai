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
            inputTokens: [
                'total' => $usage?->promptTokens ?: 0,
            ],
            outputTokens: [
                'completion' => $usage?->completionTokens ?: 0,
                'thought' => $usage?->thoughtTokens ?: 0,
            ],
            cachedTokens: [
                'write' => $usage?->cacheWriteInputTokens ?: 0,
                'read' => $usage?->cacheReadInputTokens ?: 0,
            ],
        );
    }
}
