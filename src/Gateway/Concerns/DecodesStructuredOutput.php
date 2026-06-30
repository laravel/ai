<?php

namespace Laravel\Ai\Gateway\Concerns;

trait DecodesStructuredOutput
{
    /**
     * Decode a structured output payload, tolerating markdown code fences.
     */
    protected function decodeStructuredOutput(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $payload = $this->stripJsonCodeFence($text);

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Strip a wrapping markdown code fence from the payload, if present.
     */
    private function stripJsonCodeFence(string $text): string
    {
        $trimmed = trim($text);

        if (preg_match('/^```(?:json|JSON)?\s*\n?(.*?)\n?\s*```$/s', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        return $trimmed;
    }
}
