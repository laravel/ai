<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\AudioPrompt;
use Laravel\Ai\Providers\Provider;
use Throwable;

class AudioFailed
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public AudioPrompt $prompt,
        public Throwable $exception,
    ) {}
}
