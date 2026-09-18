<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

readonly class Usage implements Arrayable, JsonSerializable
{
    /**
     * @param  int  $inputTokens  Total input tokens, including any cached or cache-written tokens.
     * @param  int  $outputTokens  Total output tokens, including any reasoning tokens.
     * @param  int|null  $cacheReadInputTokens  Subset of the input tokens read from a prompt cache, or null when unreported.
     * @param  int|null  $cacheWriteInputTokens  Subset of the input tokens written to a prompt cache, or null when unreported.
     * @param  int|null  $reasoningTokens  Subset of the output tokens spent on reasoning, or null when unreported.
     */
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?int $cacheReadInputTokens = null,
        public ?int $cacheWriteInputTokens = null,
        public ?int $reasoningTokens = null,
    ) {}

    /**
     * Get the total number of input and output tokens.
     */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * Get the input tokens that were neither read from nor written to a prompt cache.
     */
    public function uncachedInputTokens(): int
    {
        return $this->inputTokens - ($this->cacheReadInputTokens ?? 0) - ($this->cacheWriteInputTokens ?? 0);
    }

    /**
     * Add the given usage to the current usage and return a new usage instance.
     */
    public function add(Usage $usage): Usage
    {
        return new Usage(
            $this->inputTokens + $usage->inputTokens,
            $this->outputTokens + $usage->outputTokens,
            static::sum($this->cacheReadInputTokens, $usage->cacheReadInputTokens),
            static::sum($this->cacheWriteInputTokens, $usage->cacheWriteInputTokens),
            static::sum($this->reasoningTokens, $usage->reasoningTokens),
        );
    }

    /**
     * Sum two optional counts, preserving null when neither was reported.
     */
    protected static function sum(?int $a, ?int $b): ?int
    {
        return $a === null && $b === null ? null : ($a ?? 0) + ($b ?? 0);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'cache_write_input_tokens' => $this->cacheWriteInputTokens,
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
