<?php

namespace Laravel\Ai\Streaming\Events;

class ReasoningEnd extends StreamEvent
{
    public function __construct(
        public string $id,
        public string $reasoningId,
        public int $timestamp,
        public ?array $summary = null,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'reasoning_end',
            'reasoning_id' => $this->reasoningId,
            'timestamp' => $this->timestamp,
            'summary' => $this->summary,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function toVercelProtocolArray(): ?array
    {
        return [
            'type' => 'reasoning-end',
            'id' => $this->reasoningId,
        ];
    }
}
