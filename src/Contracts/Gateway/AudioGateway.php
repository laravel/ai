<?php

namespace Laravel\Ai\Contracts\Gateway;

use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Responses\AudioResponse;

interface AudioGateway
{
    /**
     * Generate audio from the given text.
     *
     * @param  array<string, string>  $headers
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
        array $headers = [],
    ): AudioResponse;
}
