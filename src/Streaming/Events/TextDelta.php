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
     * Each step of a multi-step generation is a self-contained utterance
     * (typically narration around a tool call), so steps are joined with a blank
     * line instead of being run together mid-sentence. The boundary is the step's
     * own `StreamStart` rather than a change of message ID, which Anthropic rotates
     * per content block — web search splits one answer across several, mid-sentence.
     */
    public static function combine(Collection|array $events): string
    {
        return Collection::wrap($events)
            ->chunkWhile(fn (StreamEvent $event) => ! $event instanceof StreamStart)
            ->map(fn (Collection $step) => $step->whereInstanceOf(TextDelta::class)
                ->map(fn (TextDelta $event) => $event->delta)
                ->join(''))
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
}
