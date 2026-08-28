<?php

namespace Laravel\Ai\Responses\Data;

class AudioUsage extends Usage
{
    public function __construct(
        int $promptTokens = 0,
        int $completionTokens = 0,
        int $reasoningTokens = 0,
        public float $durationSeconds = 0,
    ) {
        parent::__construct($promptTokens, $completionTokens, reasoningTokens: $reasoningTokens);
    }

    /**
     * Add the given usage to the current usage and return a new usage instance.
     */
    public function add(Usage $usage): AudioUsage
    {
        $tokens = parent::add($usage);

        return new AudioUsage(
            $tokens->promptTokens,
            $tokens->completionTokens,
            $tokens->reasoningTokens,
            $this->durationSeconds + ($usage instanceof AudioUsage ? $usage->durationSeconds : 0),
        );
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'duration_seconds' => $this->durationSeconds,
        ];
    }
}
