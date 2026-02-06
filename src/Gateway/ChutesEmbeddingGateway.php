<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;

class ChutesEmbeddingGateway implements EmbeddingGateway
{
    use Concerns\HandlesRateLimiting;

    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param  string[]  $inputs
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
    ): EmbeddingsResponse {
        $url = $provider->additionalConfiguration()['embedding_url']
            ?? 'https://chutes-qwen-qwen3-embedding-0-6b.chutes.ai/v1/embeddings';

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->timeout(120)
                ->post($url, [
                    'input' => count($inputs) === 1 ? $inputs[0] : $inputs,
                    'model' => $model,
                ])
                ->throw()
        );

        $data = $response->json();

        return new EmbeddingsResponse(
            collect($data['data'])->pluck('embedding')->all(),
            $data['usage']['total_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }
}
