<?php

namespace Laravel\Ai\Responses;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\AudioUsage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;

class TranscriptionResponse implements \Stringable
{
    public string $text;

    /** @var Collection<int, TranscriptionSegment> */
    public Collection $segments;

    public AudioUsage $usage;

    public Meta $meta;

    /**
     * @param  Collection<int, TranscriptionSegment>  $segments
     */
    public function __construct(
        string $text,
        Collection $segments,
        Usage $usage,
        Meta $meta,
    ) {
        $this->text = $text;
        $this->segments = $segments;
        $this->usage = AudioUsage::from($usage);
        $this->meta = $meta;
    }

    /**
     * Get the string representation of the transcription.
     */
    public function __toString(): string
    {
        return $this->text;
    }
}
