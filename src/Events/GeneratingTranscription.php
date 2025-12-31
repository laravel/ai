<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Providers\Provider;

class GeneratingTranscription
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public TranscriptionPrompt $prompt,
    ) {}
}
