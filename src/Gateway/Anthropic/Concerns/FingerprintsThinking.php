<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use HashContext;

trait FingerprintsThinking
{
    /**
     * Fingerprint the system prompt, tools, and messages of a request, which Anthropic binds the thinking of its response to.
     */
    protected function thinkingFingerprint(array $body): string
    {
        $prefix = $this->thinkingPrefix($body);

        foreach ($body['messages'] ?? [] as $message) {
            $this->extendThinkingPrefix($prefix, $message);
        }

        return hash_final($prefix);
    }

    /**
     * Start a request's thinking fingerprint from its system prompt and tools.
     */
    protected function thinkingPrefix(array $body): HashContext
    {
        $system = $body['system'] ?? [];

        $prefix = hash_init('xxh128');

        hash_update($prefix, $this->canonicalJson([
            is_string($system) ? [['type' => 'text', 'text' => $system]] : $system,
            $body['tools'] ?? [],
        ]));

        return $prefix;
    }

    /**
     * Extend a thinking fingerprint with the next message of the request.
     */
    protected function extendThinkingPrefix(HashContext $prefix, array $message): void
    {
        hash_update($prefix, $this->canonicalJson($message));
    }

    /**
     * Encode the given value as JSON without the parts Anthropic's prefix check ignores.
     */
    protected function canonicalJson(array $value): string
    {
        return json_encode($this->canonical($value)) ?: '';
    }

    /**
     * Sort the keys and drop the cache breakpoints of the given value.
     */
    protected function canonical(mixed $value): mixed
    {
        if (is_object($value)) {
            return (object) $this->canonical((array) $value);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonical(...), $value);
        }

        unset($value['cache_control']);

        $value = array_map($this->canonical(...), $value);

        ksort($value);

        return $value;
    }
}
