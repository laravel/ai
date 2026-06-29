<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\VideoPrompt;
use Laravel\Ai\Providers\Provider;

class GeneratingVideo
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public VideoPrompt $prompt,
    ) {}
}
