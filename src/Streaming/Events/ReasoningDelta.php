<?php

namespace Laravel\Ai\Streaming\Events;

class ReasoningDelta extends StreamEvent
{
    public function __construct(
        public string $id,
        public string $reasoningId,
        public string $delta,
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
            'type' => 'reasoning_delta',
            'reasoning_id' => $this->reasoningId,
            'delta' => $this->delta,
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
            'type' => 'reasoning-start',
            'id' => $this->reasoningId,
            'delta' => $this->delta,
        ];
    }
}
