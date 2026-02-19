<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;

class OpenAiCompatibleEmbeddingGateway implements EmbeddingGateway
{
    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param  string[]  $inputs
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions
    ): EmbeddingsResponse {
        $response = $this->client($provider)->post('/embeddings', [
            'model' => $model,
            'input' => $inputs,
            'dimensions' => $dimensions,
        ]);

        $data = $response->json();

        return new EmbeddingsResponse(
            collect((array) data_get($data, 'data', []))->pluck('embedding')->all(),
            data_get($data, 'usage.total_tokens', 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Get an HTTP client for the OpenAI compatible API.
     */
    protected function client(EmbeddingProvider $provider): PendingRequest
    {
        return Http::baseUrl($provider->additionalConfiguration()['url'] ?? 'https://api.openai.com/v1')
            ->withHeaders(array_filter([
                'Authorization' => 'Bearer '.$provider->providerCredentials()['key'],
                'Content-Type' => 'application/json',
            ]))
            ->throw();
    }
}
