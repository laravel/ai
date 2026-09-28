<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Gateway\Concerns\AnswersQuestions;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;

class LayaGateway implements ClassificationGateway
{
    use AnswersQuestions;
    use Concerns\CreatesClient;
    use HandlesFailoverErrors;

    /**
     * Get the path of the endpoint that answers questions.
     */
    protected function classificationEndpoint(): string
    {
        return '/systemone';
    }

    /**
     * Get the name of the model that answered the questions.
     *
     * Laya reports a constant decision head name as the model, so the routed checkpoint is preferred.
     */
    protected function answeringModel(array $data, string $model): string
    {
        return $data['routing']['model'] ?? $data['model'] ?? $model;
    }

    /**
     * Get an HTTP client for a Laya server.
     */
    protected function client(ClassificationProvider $provider, int $timeout = 30): PendingRequest
    {
        $key = $provider->providerCredentials()['key'];

        return $this->createClient(
            rtrim($provider->additionalConfiguration()['url'] ?? 'http://localhost:8000/v1', '/'),
            array_filter([
                'Authorization' => filled($key) ? 'Bearer '.$key : null,
                'Content-Type' => 'application/json',
            ]),
            $provider->additionalConfiguration()['headers'] ?? [],
            $timeout,
        );
    }
}
