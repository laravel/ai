<?php

namespace Laravel\Ai\Gateway\Infomaniak;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\ImageResponse;

class InfomaniakImageGateway implements ImageGateway
{
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
    ): ImageResponse {
        $config = $provider->config();

        $body = [
            'prompt' => $prompt,
            'model' => $model,
        ];

        if ($size !== null) {
            $body['size'] = $size;
        }

        if ($quality !== null) {
            $body['quality'] = $quality;
        }

        $response = Http::withToken($config['key'] ?? '')
            ->timeout($timeout ?? $config['timeout'] ?? 60)
            ->post(rtrim($config['url'] ?? 'https://api.infomaniak.com/1/ai', '/').'/openai/images/generations', $body);

        $data = $response->json();

        if ($response->failed()) {
            throw new \Laravel\Ai\Exceptions\AiException(sprintf(
                'Infomaniak Error: %s',
                $data['error']['message'] ?? 'Unknown error'
            ));
        }

        $images = collect($data['data'] ?? [])->map(
            fn ($item) => new GeneratedImage($item['url'] ?? null, $item['b64_json'] ?? null)
        );

        return new ImageResponse(
            $images,
            new \Laravel\Ai\Responses\Data\Usage(0, 0),
            new \Laravel\Ai\Responses\Data\Meta($provider->name(), $model),
        );
    }
}
