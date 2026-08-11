<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Laravel\Ai\Gateway\Concerns\CreatesClient;

trait CreatesOpenAiClient
{
    use CreatesClient;

    /**
     * Get the API URL used when the provider has no configured URL.
     */
    protected function defaultBaseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }
}
