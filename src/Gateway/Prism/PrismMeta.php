<?php

namespace Laravel\Ai\Gateway\Prism;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Meta;
use Prism\Prism\ValueObjects\Meta as PrismMetaValueObject;

class PrismMeta
{
    /**
     * Convert the Prism meta value object to a Laravel AI SDK usage object.
     */
    public static function toLaravelMeta(
        ?string $provider,
        ?PrismMetaValueObject $meta,
        ?Collection $citations = null
    ): Meta {
        return new Meta(
            provider: $provider,
            model: $meta?->model ?? null,
            citations: $citations,
            extra: $meta
                ? collect(array_diff_key($meta->toArray(), ['model' => true]))
                : null
        );
    }
}
