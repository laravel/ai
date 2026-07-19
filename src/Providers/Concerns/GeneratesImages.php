<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\GeneratingImage;
use Laravel\Ai\Events\ImageGenerated;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Prompts\ImagePrompt;
use Laravel\Ai\Responses\ImageResponse;

trait GeneratesImages
{
    /**
     * Generate an image.
     *
     * @param  array<Image>  $attachments
     * @param  'low'|'medium'|'high'|null  $quality
     * @param  array<string, string>  $headers
     */
    public function image(
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?string $model = null,
        ?int $timeout = null,
        array $headers = [],
    ): ImageResponse {
        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultImageModel();

        $prompt = new ImagePrompt($prompt, $attachments, $size, $quality, $this, $model, $headers);

        if (Ai::imagesAreFaked()) {
            Ai::recordImageGeneration($prompt);
        }

        $this->events->dispatch(new GeneratingImage(
            $invocationId, $this, $model, $prompt,
        ));

        return tap($this->imageGateway()->generateImage(
            $this, $model, $prompt->prompt, $prompt->attachments->all(), $prompt->size, $prompt->quality, $timeout, $prompt->headers,
        ), function (ImageResponse $response) use ($invocationId, $prompt, $model): void {
            $this->events->dispatch(new ImageGenerated(
                $invocationId, $this, $model, $prompt, $response,
            ));
        });
    }
}
