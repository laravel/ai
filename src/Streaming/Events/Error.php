<?php

namespace Laravel\Ai\Streaming\Events;

class Error extends StreamEvent
{
    public function __construct(
        public string $id,
        public string $type,
        public string $message,
        public bool $recoverable,
        public int $timestamp,
        public ?array $metadata = null,
    ) {
        //
    }

    /**
     * Get the name the event is broadcast under.
     *
     * A provider error's type is the provider's own error code, which varies
     * by provider and failure, so it is broadcast under one name clients can
     * listen for. A failed broadcast job keeps its own "stream_failed" name.
     */
    public function broadcastAs(): string
    {
        return $this->type === 'stream_failed' ? $this->type : 'error';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => $this->type,
            'message' => $this->message,
            'recoverable' => $this->recoverable,
            'timestamp' => $this->timestamp,
            'metadata' => $this->metadata,
        ];
    }
}
