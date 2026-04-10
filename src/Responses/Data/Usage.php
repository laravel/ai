<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class Usage implements Arrayable, JsonSerializable
{
    public function __construct(
        public ?array $inputTokens = [],
        public ?array $outputTokens = [],
        public ?array $cachedTokens = [],
        public ?array $toolsTokens = [],
    ) {}

    /**
     * Add the given usage to the current usage and return a new usage instance.
     */
    public function add(Usage $usage): Usage
    {
        return new Usage(
            $this->accumulate($this->inputTokens, $usage->inputTokens),
            $this->accumulate($this->outputTokens, $usage->outputTokens),
            $this->accumulate($this->cachedTokens, $usage->cachedTokens),
            $this->accumulate($this->toolsTokens, $usage->toolsTokens),
        );
    }

    /** 
     * Accumulates elements from two collections.
     */
    private function accumulate(array $a1, array $a2): array
    {
        $result = [];
        foreach (array_keys($a1 + $a2) as $key) $result[$key] = ($a1[$key] ?? 0) + ($a2[$key] ?? 0);
        return $result;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cached_tokens' => $this->cachedTokens,
            'tools_tokens' => $this->toolsTokens,
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
