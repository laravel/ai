<?php

namespace Laravel\Ai\Responses\Data;

class AudioUsage extends Usage
{
    public function __construct(
        int $promptTokens = 0,
        int $completionTokens = 0,
        int $cacheWriteInputTokens = 0,
        int $cacheReadInputTokens = 0,
        int $reasoningTokens = 0,
        public float $durationSeconds = 0,
    ) {
        parent::__construct($promptTokens, $completionTokens, $cacheWriteInputTokens, $cacheReadInputTokens, $reasoningTokens);
    }

    /**
     * Create an audio usage instance from the given usage.
     */
    public static function from(Usage $usage): AudioUsage
    {
        return $usage instanceof AudioUsage ? $usage : new AudioUsage(
            $usage->promptTokens,
            $usage->completionTokens,
            $usage->cacheWriteInputTokens,
            $usage->cacheReadInputTokens,
            $usage->reasoningTokens,
        );
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
            $tokens->cacheWriteInputTokens,
            $tokens->cacheReadInputTokens,
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
            ...parent::toArray(),
            'duration_seconds' => $this->durationSeconds,
        ];
    }
}
