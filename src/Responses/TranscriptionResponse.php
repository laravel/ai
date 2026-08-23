<?php

namespace Laravel\Ai\Responses;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;

class TranscriptionResponse implements \Stringable
{
    public string $text;

    /** @var Collection<int, TranscriptionSegment> */
    public Collection $segments;

    public Usage $usage;

    public Meta $meta;

    /**
     * The length of the transcribed audio in seconds, when the provider reports it.
     *
     * This is the audio itself, not the duration a duration billed model was billed
     * for, which is rounded up and lives on the usage.
     */
    public ?float $duration;

    /**
     * @param  Collection<int, TranscriptionSegment>  $segments
     */
    public function __construct(
        string $text,
        Collection $segments,
        Usage $usage,
        Meta $meta,
        ?float $duration = null,
    ) {
        $this->text = $text;
        $this->segments = $segments;
        $this->usage = $usage;
        $this->meta = $meta;
        $this->duration = $duration;
    }

    /**
     * Get the string representation of the transcription.
     */
    public function __toString(): string
    {
        return $this->text;
    }
}
