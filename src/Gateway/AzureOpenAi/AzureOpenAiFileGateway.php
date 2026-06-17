<?php

namespace Laravel\Ai\Gateway\AzureOpenAi;

use Laravel\Ai\Gateway\OpenAi\OpenAiFileGateway;

class AzureOpenAiFileGateway extends OpenAiFileGateway
{
    use Concerns\CreatesAzureOpenAiClient;

    /**
     * Get the default purpose to use when a file does not specify one.
     */
    protected function defaultPurpose(): string
    {
        return 'assistants';
    }
}
