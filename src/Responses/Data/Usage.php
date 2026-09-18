<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

readonly class Usage implements Arrayable, JsonSerializable
{
    /**
     * @param  int  $inputTokens  Total input tokens.
     * @param  int  $outputTokens  Total output tokens.
     */
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}

    /**
     * Get the total number of input and output tokens.
     */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
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
