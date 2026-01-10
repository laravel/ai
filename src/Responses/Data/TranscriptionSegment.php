<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class TranscriptionSegment implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $text,
        public string $speaker,
        public float $startSeconds,
        public float $endSeconds,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray()
    {
        return [
            'text' => $this->text,
            'speaker' => $this->speaker,
            'start_seconds' => $this->startSeconds,
            'end_seconds' => $this->endSeconds,
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
