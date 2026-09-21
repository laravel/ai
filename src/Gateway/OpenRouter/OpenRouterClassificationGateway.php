<?php

namespace Laravel\Ai\Gateway\OpenRouter;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Gateway\Concerns\AnswersQuestions;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Providers\Provider;

class OpenRouterClassificationGateway implements ClassificationGateway
{
    use AnswersQuestions;
    use Concerns\CreatesOpenRouterClient {
        baseUrl as openRouterBaseUrl;
    }
    use HandlesFailoverErrors;

    /**
     * Get the path of the endpoint that answers questions.
     */
    protected function classificationEndpoint(): string
    {
        return '/alpha/decisions';
    }

    /**
     * Get the base URL for the Decisions API, which is served outside of the versioned API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return Str::chopEnd($this->openRouterBaseUrl($provider), '/v1');
    }
}
