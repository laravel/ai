<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Providers\Provider;
use Laravel\Ai\TranscriptionPrompt;

class GeneratingTranscription
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public TranscriptionPrompt $prompt,
    ) {}
}
