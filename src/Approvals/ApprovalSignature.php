<?php

namespace Laravel\Ai\Approvals;

use Illuminate\Encryption\Encrypter;
use JsonException;

class ApprovalSignature
{
    /**
     * Sign a pending tool call so it can only be approved as the server issued it.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function sign(string $id, string $tool, array $arguments): string
    {
        return static::hash($id, $tool, $arguments, static::encrypter()->getKey());
    }

    /**
     * Determine whether the signature was issued for exactly this tool call.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function verify(string $signature, string $id, string $tool, array $arguments): bool
    {
        try {
            foreach (static::encrypter()->getAllKeys() as $key) {
                if (hash_equals(static::hash($id, $tool, $arguments, $key), $signature)) {
                    return true;
                }
            }
        } catch (JsonException) {
            return false;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @throws JsonException
     */
    protected static function hash(string $id, string $tool, array $arguments, string $key): string
    {
        $payload = json_encode(
            [$id, $tool, static::normalize($arguments)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return hash_hmac('sha256', 'laravel-ai.tool-approval|'.$payload, $key);
    }

    /**
     * Order object keys so arguments hash the same after a round trip through a JavaScript client.
     */
    protected static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(static::normalize(...), $value);
    }

    protected static function encrypter(): Encrypter
    {
        return app('encrypter');
    }
}
