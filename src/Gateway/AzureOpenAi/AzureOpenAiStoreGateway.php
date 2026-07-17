<?php

namespace Laravel\Ai\Gateway\AzureOpenAi;

use Laravel\Ai\Gateway\OpenAi\OpenAiStoreGateway;

class AzureOpenAiStoreGateway extends OpenAiStoreGateway
{
    use Concerns\CreatesAzureOpenAiClient;
}
