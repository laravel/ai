<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Messages\Attachments\Image as ImageAttachment;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;

class XaiImageGateway implements ImageGateway
{
    /**
     * Generate an image.
     *
     * @param  array<ImageAttachment>  $attachments
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
    ): ImageResponse {
        try {
            $response = Http::withToken($provider->providerCredentials()['key'])
                ->timeout(60)
                ->post('https://api.x.ai/v1/images/generations', [
                    'model' => $model,
                    'prompt' => $prompt,
                    'response_format' => 'b64_json',
                ])
                ->throw();
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw RateLimitedException::forProvider(
                    $provider->name(), $e->getCode(), $e
                );
            }

            throw $e;
        }

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
