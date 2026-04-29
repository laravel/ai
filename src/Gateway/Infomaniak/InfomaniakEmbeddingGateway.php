<?php

namespace Laravel\Ai\Gateway\Infomaniak;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Responses\EmbeddingsResponse;

class InfomaniakEmbeddingGateway implements EmbeddingGateway
{
    public function generateEmbeddings(EmbeddingProvider $provider, string $model, array $inputs, int $dimensions, int $timeout = 30): EmbeddingsResponse
    {
        $config = $provider->config();

        $response = Http::withToken($config['key'] ?? '')
            ->timeout($timeout)
            ->post(rtrim($config['url'] ?? 'https://api.infomaniak.com/1/ai', '/').'/openai/embeddings', [
                'input' => $inputs,
                'model' => $model,
                'dimensions' => $dimensions,
            ]);

        $data = $response->json();

        if ($response->failed()) {
            throw new \Laravel\Ai\Exceptions\AiException(sprintf(
                'Infomaniak Error: %s',
                $data['error']['message'] ?? 'Unknown error'
            ));
        }

        return new EmbeddingsResponse(
            array_map(fn ($item) => $item['embedding'], $data['data'] ?? []),
            $data['usage']['total_tokens'] ?? 0,
            new \Laravel\Ai\Responses\Data\Meta($provider->name(), $model),
        );
    }
}
