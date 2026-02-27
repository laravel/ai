<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;

class ModerationCategory implements Arrayable, JsonSerializable, Stringable
{
    /**
     * Create a new moderation category instance.
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $flagged,
        public readonly float $score,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'flagged' => $this->flagged,
            'score' => $this->score,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Get the category name.
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
