<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\ImagePrompt;
use Laravel\Ai\Providers\Provider;

class GeneratingImage
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public ImagePrompt $prompt,
    ) {}
}
