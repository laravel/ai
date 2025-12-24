<?php

namespace Laravel\Ai\Streaming\Events;

class StreamStart extends StreamEvent
{
    public function __construct(
        public string $id,
        public string $provider,
        public string $model,
        public int $timestamp,
        public ?array $metadata = null,
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
            'type' => 'stream_start',
            'provider' => $this->provider,
            'model' => $this->model,
            'timestamp' => $this->timestamp,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function toVercelProtocolArray(): ?array
    {
        return [
            'type' => 'start',
            'messageId' => $this->id,
        ];
    }
}
