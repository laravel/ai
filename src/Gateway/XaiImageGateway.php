<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;

class XaiImageGateway implements ImageGateway
{
    use Concerns\HandlesRateLimiting;

    /**
     * Generate an image.
     *
     * @param  array<ImageFile>  $attachments
     * @param  '3:2'|'2:3'|'1:1'  $size
     * @param  'low'|'medium'|'high'  $quality
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        int $timeout = 60,
    ): ImageResponse {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->timeout($timeout)
                ->post('https://api.x.ai/v1/images/generations', [
                    'model' => $model,
                    'prompt' => $prompt,
                    'response_format' => 'b64_json',
                ])
                ->throw()
        );

        $response = $response->json();

        return new ImageResponse(
            new Collection([
                new GeneratedImage($response['data'][0]['b64_json'], 'image/jpeg'),
            ]),
            new Usage,
            new Meta($provider->name(), $model),
        );
    }
}
