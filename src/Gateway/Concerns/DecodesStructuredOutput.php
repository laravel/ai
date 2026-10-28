<?php

namespace Laravel\Ai\Gateway\Concerns;

use Laravel\Ai\Exceptions\InvalidStructuredOutputException;

trait DecodesStructuredOutput
{
    /**
     * Decode a structured output payload, tolerating markdown code fences.
     *
     * @throws InvalidStructuredOutputException
     */
    protected function decodeStructuredOutput(?string $text, bool $strict = false): array
    {
        $trimmed = trim((string) $text);

        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)) {
            $decoded = json_decode($this->extractJsonCodeFence($trimmed) ?? '', true);
        }

        if (! is_array($decoded)) {
            $decoded = json_decode($this->escapeQuotesInObjectKeys($this->extractJsonCodeFence($trimmed) ?? $trimmed), true);
        }

        if (is_array($decoded)) {
            return $decoded;
        }

        if ($strict) {
            throw new InvalidStructuredOutputException((string) $text);
        }

        return [];
    }

    /**
     * Escape unescaped double quotes in object keys, where only a quote followed by a colon closes the key.
     */
    private function escapeQuotesInObjectKeys(string $json): string
    {
        $result = '';
        $containers = [];
        $offset = 0;

        while (preg_match('/\G([^"]*)"/', $json, $match, offset: $offset) === 1) {
            $structure = $match[1];

            preg_match_all('/[{}\[\]]/', $structure, $brackets);

            foreach ($brackets[0] as $bracket) {
                if ($bracket === '{' || $bracket === '[') {
                    $containers[] = $bracket;
                } else {
                    array_pop($containers);
                }
            }

            $isKey = end($containers) === '{' && preg_match('/[{,]\s*$/', $structure) === 1;

            $pattern = $isKey
                ? '/\G"((?:\\\\.|[^\\\\])*?)"(?=\s*:)/s'
                : '/\G"((?:\\\\.|[^"\\\\])*)"/s';

            if (preg_match($pattern, $json, $string, offset: $offset + strlen($structure)) !== 1) {
                break;
            }

            $contents = $isKey
                ? preg_replace_callback('/\\\\.|"/s', fn (array $token) => $token[0] === '"' ? '\\"' : $token[0], $string[1])
                : $string[1];

            $result .= $structure.'"'.$contents.'"';
            $offset += strlen($structure) + strlen($string[0]);
        }

        return $result.substr($json, $offset);
    }

    /**
     * Extract the contents of a markdown code fence from the payload, if present.
     */
    private function extractJsonCodeFence(string $text): ?string
    {
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/si', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }
}
