<?php

namespace Laravel\Ai\Streaming\Events;

use Illuminate\Support\Collection;

class TextDelta extends StreamEvent
{
    public function __construct(
        public string $id,
        public string $messageId,
        public string $delta,
        public int $timestamp,
    ) {
        //
    }

    /**
     * Combine the text deltas in the given collection of events into a single string.
     */
    public static function combine(Collection|array $events): string
    {
        $events = is_array($events) ? new Collection($events) : $events;

        return $events->whereInstanceOf(TextDelta::class)->map->delta->join('');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'text_delta',
            'message_id' => $this->messageId,
            'delta' => $this->delta,
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function toVercelProtocolArray(): ?array
    {
        return [
            'type' => 'text-delta',
            'id' => $this->messageId,
            'delta' => $this->delta,
        ];
    }
}
