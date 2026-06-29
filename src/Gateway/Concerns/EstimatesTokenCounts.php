<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;

trait EstimatesTokenCounts
{
    /**
     * Estimate tokens for a text generation request.
     *
     * Uses character-based heuristics for providers without native token counting.
     * Approximate: 1 token per 4 characters, with 30% overhead for formatting/metadata.
     *
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     */
    public function countTokens(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
    ): int {
        $tokenCount = 0;

        if (filled($instructions)) {
            $tokenCount += $this->estimateTokens($instructions);
        }

        foreach ($messages as $message) {
            $tokenCount += $this->estimateTokens($message->content ?? '');
        }

        foreach ($tools as $tool) {
            $tokenCount += $this->estimateTokens(json_encode($tool));
        }

        if (filled($schema)) {
            $tokenCount += $this->estimateTokens(json_encode($schema));
        }

        return max(1, (int) ($tokenCount * 1.3));
    }

    /**
     * Estimate tokens from text content.
     *
     * Uses simple heuristic: approximately 1 token per 4 characters.
     */
    protected function estimateTokens(string $content): int
    {
        $charCount = mb_strlen($content);

        return (int) ceil($charCount / 4);
    }
}
