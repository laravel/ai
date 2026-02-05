<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\ModelGateway;
use Laravel\Ai\Contracts\Providers\ModelProvider;
use Laravel\Ai\Responses\ModelDeleteResponse;
use Laravel\Ai\Responses\ModelListResponse;
use Laravel\Ai\Responses\ModelResponse;

class OpenAiModelGateway implements ModelGateway
{
    use Concerns\HandlesRateLimiting;

    /**
     * List all available models.
     */
    public function listModels(ModelProvider $provider, array $options = []): ModelListResponse
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->get('https://api.openai.com/v1/models')
                ->throw()
        );

        $models = collect($response->json('data', []))->map(
            fn ($model) => new ModelResponse(
                id: $model['id'],
                object: $model['object'],
                created: $model['created'],
                ownedBy: $model['owned_by'],
            )
        )->all();

        return new ModelListResponse($models);
    }

    /**
     * Retrieve details of a specific model.
     */
    public function retrieveModel(ModelProvider $provider, string $modelId): ModelResponse
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->get("https://api.openai.com/v1/models/{$modelId}")
                ->throw()
        );

        return new ModelResponse(
            id: $response->json('id'),
            object: $response->json('object'),
            created: $response->json('created'),
            ownedBy: $response->json('owned_by'),
        );
    }

    /**
     * Delete a fine-tuned model.
     */
    public function deleteModel(ModelProvider $provider, string $modelId): ModelDeleteResponse
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->delete("https://api.openai.com/v1/models/{$modelId}")
                ->throw()
        );

        return new ModelDeleteResponse(
            id: $response->json('id'),
            object: $response->json('object'),
            deleted: $response->json('deleted'),
        );
    }
}
