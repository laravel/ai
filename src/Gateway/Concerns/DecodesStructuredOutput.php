<?php

namespace Laravel\Ai\Gateway\Concerns;

trait DecodesStructuredOutput
{
    /**
     * Decode a structured output payload, tolerating markdown code fences.
     */
    protected function decodeStructuredOutput(?string $text): array
    {
        $trimmed = trim((string) $text);

        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)) {
            $decoded = json_decode($this->extractJsonCodeFence($trimmed) ?? '', true);
        }

        return is_array($decoded) ? $decoded : [];
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
