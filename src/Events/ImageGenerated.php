<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\ImagePrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\ImageResponse;

class ImageGenerated
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public ImagePrompt $prompt,
        public ImageResponse $response,
    ) {}
}
