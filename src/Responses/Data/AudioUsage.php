<?php

namespace Laravel\Ai\Responses\Data;

class AudioUsage extends Usage
{
    public function __construct(
        int $promptTokens = 0,
        int $completionTokens = 0,
        public float $durationSeconds = 0,
    ) {
        parent::__construct($promptTokens, $completionTokens);
    }

    /**
     * Add the given usage to the current usage and return a new usage instance.
     */
    public function add(Usage $usage): AudioUsage
    {
        return new AudioUsage(
            $this->promptTokens + $usage->promptTokens,
            $this->completionTokens + $usage->completionTokens,
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
