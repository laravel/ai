<?php

namespace Laravel\Ai\Responses;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class ModerationResult implements Arrayable, JsonSerializable
{
    /**
     * Create a new moderation result instance.
     */
    public function __construct(
        public bool $flagged,
        public array $categories,
        public array $categoryScores,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray()
    {
        return [
            'flagged' => $this->flagged,
            'categories' => $this->categories,
            'category_scores' => $this->categoryScores,
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
