<?php

namespace Laravel\Ai\Enums;

use Illuminate\Support\Arr;
use InvalidArgumentException;

enum PromptCacheTarget: string
{
    case System = 'system';
    case Tools = 'tools';

    private const TTLS = ['5m', '1h'];

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

            $normalized[($target instanceof self ? $target : self::from($target))->value] = is_int($key)
                ? null
                : self::normalizeTtl($value);
        }

        return $normalized;
    }

    /**
     * Normalize the TTL requested for a target.
     */
    protected static function normalizeTtl(mixed $ttl): ?string
    {
        if ($ttl === true || $ttl === null) {
            return null;
        }

        if (! in_array($ttl, self::TTLS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported prompt cache TTL [%s]. Supported values are [%s].',
                is_scalar($ttl) ? $ttl : get_debug_type($ttl),
                implode(', ', self::TTLS),
            ));
        }

        return $ttl;
    }
}
