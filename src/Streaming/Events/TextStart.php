<?php

namespace Laravel\Ai\Streaming\Events;

class TextStart extends StreamEvent
{
    public function __construct(
        public string $id,
        public string $messageId,
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
            'type' => 'text_start',
            'message_id' => $this->messageId,
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function toVercelProtocolArray(): ?array
    {
        return [
            'type' => 'text-start',
            'id' => $this->messageId,
        ];
    }
}
