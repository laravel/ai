<?php

namespace Laravel\Ai\Streaming\Events;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Step;

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
     * Combine the text deltas, starting a new paragraph only after a step that ran tools.
     */
    public static function combine(Collection|array $events): string
    {
        return static::join(Collection::wrap($events)
            ->chunkWhile(fn (StreamEvent $event, $key, Collection $step) => ! $event instanceof StreamStart
                || $step->doesntContain(fn (StreamEvent $previous) => $previous instanceof ToolResult))
            ->map(fn (Collection $step) => $step->whereInstanceOf(TextDelta::class)
                ->map(fn (TextDelta $event) => $event->delta)
                ->join('')));
    }

    /**
     * Combine the text of the given steps, starting a new paragraph only after a step that ran tools.
     *
     * @param  Collection<array-key, Step>|array<array-key, Step>  $steps
     */
    public static function combineSteps(Collection|array $steps): string
    {
        return static::join(Collection::wrap($steps)
            ->chunkWhile(fn (Step $step, $key, Collection $previous) => blank($previous->last()->toolResults))
            ->map(fn (Collection $steps) => $steps->pluck('text')->join('')));
    }

    /**
     * Join the text of each step, separating the steps with a blank line.
     *
     * @param  Collection<array-key, string>|array<array-key, string>  $texts
     */
    public static function join(Collection|array $texts): string
    {
        return Collection::wrap($texts)
            ->filter(fn (string $text) => trim($text) !== '')
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
