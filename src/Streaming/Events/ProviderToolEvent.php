<?php

namespace Laravel\Ai\Streaming\Events;

use Laravel\Ai\Streaming\Events\StreamEvent;

class ProviderToolEvent extends StreamEvent
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $id,
        public string $itemId,
        public string $type,
        public array $data,
        public string $status,
        public int $timestamp,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->itemId,
            'type' => $this->type,
            'data' => $this->data,
            'status' => $this->status,
            'timestamp' => $this->timestamp,
        ];
    }
}
