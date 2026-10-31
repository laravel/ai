<?php

namespace Laravel\Ai\Gateway\OpenAiCompatible;

use Laravel\Ai\Gateway\OpenAi\OpenAiClassificationGateway;

class OpenAiCompatibleClassificationGateway extends OpenAiClassificationGateway
{
    use Concerns\CreatesOpenAiCompatibleClient;
}
