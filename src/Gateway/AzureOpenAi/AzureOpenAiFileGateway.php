<?php

namespace Laravel\Ai\Gateway\AzureOpenAi;

use Laravel\Ai\Gateway\OpenAi\OpenAiFileGateway;

class AzureOpenAiFileGateway extends OpenAiFileGateway
{
    use Concerns\CreatesAzureOpenAiClient;

    /**
     * Get the file upload purpose.
     */
    protected function filePurpose(): string
    {
        return 'assistants';
    }
}
