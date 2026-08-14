<?php

namespace Laravel\Ai\Enums;

use Illuminate\Support\Arr;

enum PromptCacheTarget: string
{
    case System = 'system';
    case Tools = 'tools';

    /**
     * Normalize a prompt cache option into target and TTL pairs.
     *
     * @return array<string, string|null>
     */
    public static function normalize(mixed $targets): array
    {
        $normalized = [];

        foreach (Arr::wrap($targets) as $key => $value) {
            $target = is_int($key) ? $value : $key;

            $normalized[($target instanceof self ? $target : self::from($target))->value] = is_int($key) || ! is_string($value)
                ? null
                : $value;
        }

        return $normalized;
    }
}
