<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class Usage implements Arrayable, JsonSerializable
{
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $cacheWriteInputTokens = 0,
        public int $cacheReadInputTokens = 0,
        public int $reasoningTokens = 0,
    ) {}

    /**
     * Add the given usage to the current usage and return a new usage instance.
     */
    public function add(Usage $usage): Usage
    {
        return new Usage(
            $this->promptTokens + $usage->promptTokens,
            $this->completionTokens + $usage->completionTokens,
            $this->cacheWriteInputTokens + $usage->cacheWriteInputTokens,
            $this->cacheReadInputTokens + $usage->cacheReadInputTokens,
            $this->reasoningTokens + $usage->reasoningTokens,
        );
    }

    /**
     * Get the instance as an array.
     */
    public function toArray()
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'cache_write_input_tokens' => $this->cacheWriteInputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
