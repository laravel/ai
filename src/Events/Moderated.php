<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\ModerationPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\ModerationResponse;

class Moderated
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public ModerationPrompt $prompt,
        public ModerationResponse $response,
    ) {}
}
