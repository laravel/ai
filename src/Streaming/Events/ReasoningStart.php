<?php

namespace Laravel\Ai\Streaming\Events;

class ReasoningStart extends StreamEvent
{
    public function __construct(
        public string $id,
        public string $reasoningId,
        public int $timestamp,
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
            'type' => 'reasoning_start',
            'reasoning_id' => $this->reasoningId,
            'timestamp' => $this->timestamp,
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
        ];
    }
}
