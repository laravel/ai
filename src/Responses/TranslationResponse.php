<?php

namespace Laravel\Ai\Responses;

use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

class TranslationResponse
{
    public string $text;

    public Usage $usage;

    public Meta $meta;

    public function __construct(
        string $text,
        Usage $usage,
        Meta $meta,
    ) {
        $this->text = $text;
        $this->usage = $usage;
        $this->meta = $meta;
    }

    /**
     * Get the string representation of the translation.
     */
    public function __toString(): string
    {
        return $this->text;
    }
}
