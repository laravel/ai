<?php

namespace Laravel\Ai\Streaming\Events;

class TextEnd extends StreamEvent
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
            'type' => 'text_end',
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
            'type' => 'text-end',
            'id' => $this->messageId,
        ];
    }
}
