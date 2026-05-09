<?php

namespace Laravel\Ai\Streaming\Events;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Usage;

class StreamEnd extends StreamEvent
{
    public function __construct(
        public string $id,
        public string $reason,
        public Usage $usage,
        public int $timestamp,
        public ?string $responseId = null,
    ) {
        //
    }

    /**
     * Combine the stream end usages in the given collection of events into a single usage instance.
     */
    public static function combineUsage(Collection|array $events): Usage
    {
        $events = is_array($events) ? new Collection($events) : $events;

        return $events->whereInstanceOf(StreamEnd::class)
            ->values()
            ->map
            ->usage
            ->reduce(fn ($a, $b) => $a->add($b), new Usage);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'stream_end',
            'reason' => $this->reason,
            'usage' => $this->usage instanceof Usage
                ? $this->usage->toArray()
                : null,
            'timestamp' => $this->timestamp,
            'response_id' => $this->responseId,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function toVercelProtocolArray(): ?array
    {
        return [
            'type' => 'finish',
        ];
    }
}
