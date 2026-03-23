<?php

namespace Laravel\Ai;

use Laravel\Ai\PendingResponses\PendingVideoGeneration;

class Video
{
    /**
     * Generate a video from a text prompt.
     */
    public static function of(string $prompt): PendingVideoGeneration
    {
        return new PendingVideoGeneration($prompt);
    }
}
