<?php

namespace Laravel\Ai\Responses;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

class TranscriptionResponse
{
    public string $text;

    public ?string $language;

    public Collection $segments;

    public Usage $usage;

    public Meta $meta;

    public function __construct(
        string $text,
        Collection $segments,
        Usage $usage,
        Meta $meta,
        ?string $language = null,
    ) {
        $this->text = $text;
        $this->language = $language;
        $this->segments = $segments;
        $this->usage = $usage;
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
