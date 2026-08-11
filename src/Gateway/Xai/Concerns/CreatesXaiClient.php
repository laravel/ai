<?php

namespace Laravel\Ai\Gateway\Xai\Concerns;

use Laravel\Ai\Gateway\Concerns\CreatesClient;

trait CreatesXaiClient
{
    use CreatesClient;

    /**
     * Get the API URL used when the provider has no configured URL.
     */
    protected function defaultBaseUrl(): string
    {
        return 'https://api.x.ai/v1';
    }
}
