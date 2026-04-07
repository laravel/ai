<?php

namespace Laravel\Ai\Gateway;

class AzureOpenAiFileGateway extends OpenAi\OpenAiFileGateway
{
    use AzureOpenAi\Concerns\CreatesAzureOpenAiClient;

    /**
     * Get the file upload purpose.
     */
    protected function filePurpose(): string
    {
        return 'assistants';
    }
}
