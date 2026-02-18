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

class ModelsLabImageGateway implements ImageGateway
{
    /**
     * Generate an image using ModelsLab API.
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
        $response = Http::withToken($provider->providerCredentials()['key'])
            ->timeout($timeout ?? 180)
            ->post($provider->imageGateway()->baseUrl() . '/images/text2img', [
                'prompt' => $prompt,
                'model' => $model,
                'guidance' => 3.5,
                'samples' => 1,
                'safety_checker' => false,
            ])
            ->throw()
            ->json();

        // ModelsLab returns base64 directly in the response
        $images = new Collection();
        
        if (isset($response['output']) && is_array($response['output'])) {
            foreach ($response['output'] as $imageData) {
                if (is_string($imageData)) {
                    $images->push(new GeneratedImage($imageData, 'image/png'));
                }
            }
        }

        return new ImageResponse(
            $images,
            new Usage(
                $response['usage']['prompt_tokens'] ?? 0,
                $response['usage']['completion_tokens'] ?? 0,
                $response['usage']['total_tokens'] ?? 0
            ),
            new Meta($provider->name(), $model)
        );
    }
}
