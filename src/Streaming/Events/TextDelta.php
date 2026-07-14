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
     *
     * Deltas from a multi-step generation carry a distinct message ID per step,
     * and each step's text is a self-contained utterance (typically narration
     * around a tool call). Steps are therefore joined with a blank line instead
     * of being run together mid-sentence.
     */
    public static function combine(Collection|array $events): string
    {
        $events = is_array($events) ? new Collection($events) : $events;

        return $events->whereInstanceOf(TextDelta::class)
            ->groupBy(fn (TextDelta $event) => $event->messageId)
            ->map(fn (Collection $deltas) => $deltas->map(fn (TextDelta $event) => $event->delta)->join(''))
            ->filter(fn (string $text) => trim($text) !== '')
            ->values()
            ->join("\n\n");
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
