<?php

namespace Laravel\Ai\Gateway;

class AzureOpenAiStoreGateway extends OpenAi\OpenAiStoreGateway
{
    use AzureOpenAi\Concerns\CreatesAzureOpenAiClient;
}
