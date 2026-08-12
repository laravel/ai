<?php

namespace Laravel\Ai\Enums;

use Illuminate\Support\Arr;

enum PromptCacheTarget: string
{
    case System = 'system';
    case Tools = 'tools';

    /**
     * @return array<int, self>
     */
    public static function normalize(mixed $targets): array
    {
        return array_map(
            fn ($target) => $target instanceof self ? $target : self::from($target),
            Arr::wrap($targets)
        );
    }

    public function requestedIn(array $targets): bool
    {
        return in_array($this, $targets, true);
    }
}
