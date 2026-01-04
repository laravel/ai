<?php

namespace Laravel\Ai\Streaming\Events;

use Laravel\Ai\Responses\Data\Citation as CitationData;
use Laravel\Ai\Responses\Data\UrlCitation;

class Citation extends StreamEvent
{
    public function __construct(
        public string $id,
        public string $messageId,
        public CitationData $citation,
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
            'type' => 'citation',
            'message_id' => $this->messageId,
            'citation' => match (true) {
                $this->citation instanceof UrlCitation => [
                    'title' => $this->citation->title,
                    'url' => $this->citation->url,
                ],
            },
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function toVercelProtocolArray(): ?array
    {
        return match (true) {
            $this->citation instanceof UrlCitation => [
                'type' => 'source-url',
                'sourceId' => $this->citation->url,
                'url' => $this->citation->url,
            ],
        };
    }
}
