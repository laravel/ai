<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\TranscriptionResponse;

class TranscriptionGenerated
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public TranscriptionPrompt $prompt,
        public TranscriptionResponse $response,
    ) {}
}
