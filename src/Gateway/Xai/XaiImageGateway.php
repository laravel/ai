<?php

namespace Laravel\Ai\Gateway\Xai;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;

class XaiImageGateway implements ImageGateway
{
    use Concerns\CreatesXaiClient;
    use HandlesFailoverErrors;

    /**
     * Generate an image.
     *
     * @param  array<Image>  $attachments
     * @param  'low'|'medium'|'high'|null  $quality
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

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout ?? 120)
                ->post('images/generations', array_merge(array_filter([
                    'model' => $model,
                    'prompt' => $prompt,
                    'response_format' => 'b64_json',
                ]), $options))
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
