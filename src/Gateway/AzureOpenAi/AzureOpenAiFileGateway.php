<?php

namespace Laravel\Ai\Gateway\AzureOpenAi;

use Laravel\Ai\Gateway\OpenAi\OpenAiFileGateway;

class AzureOpenAiFileGateway extends OpenAiFileGateway
{
    use Concerns\CreatesAzureOpenAiClient;

    /**
     * Get the default purpose to use when a file does not specify one.
     *
     * @see https://learn.microsoft.com/en-us/answers/questions/2265270/will-azure-openai-add-support-for-user-data-purpos
     */
    protected function defaultPurpose(): string
    {
        return 'assistants';
    }
}
