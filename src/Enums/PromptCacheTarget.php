<?php

namespace Laravel\Ai\Enums;

use Illuminate\Support\Arr;

enum PromptCacheTarget: string
{
    case System = 'system';
    case Tools = 'tools';

    /**
     * Normalize a raw prompt cache option value into target cases.
     *
     * @return array<int, self>
     */
    public static function normalize(mixed $targets): array
    {
        return array_map(
            fn (self|string $target): self => $target instanceof self ? $target : self::from($target),
            Arr::wrap($targets ?: []),
        );
    }
}
