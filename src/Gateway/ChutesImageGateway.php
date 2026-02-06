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

class ChutesImageGateway implements ImageGateway
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
        ?int $timeout = null,
    ): ImageResponse {
        $options = $provider->defaultImageOptions($size, $quality);

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->timeout($timeout ?? 120)
                ->post($provider->additionalConfiguration()['image_url'] ?? 'https://image.chutes.ai/generate', [
                    'model' => $model,
                    'prompt' => $prompt,
                    'width' => $options['width'] ?? 1024,
                    'height' => $options['height'] ?? 1024,
                    'num_inference_steps' => $options['steps'] ?? 10,
                    'guidance_scale' => $options['guidance_scale'] ?? 7.5,
                ])
                ->throw()
        );

        return new ImageResponse(
            new Collection([
                new GeneratedImage(base64_encode($response->body()), 'image/jpeg'),
            ]),
            new Usage,
            new Meta($provider->name(), $model),
        );
    }
}
