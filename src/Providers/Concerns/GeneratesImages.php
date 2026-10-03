<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\GeneratingImage;
use Laravel\Ai\Events\ImageFailed;
use Laravel\Ai\Events\ImageGenerated;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Prompts\ImagePrompt;
use Laravel\Ai\Responses\ImageResponse;
use Throwable;

trait GeneratesImages
{
    /**
     * Generate an image.
     *
     * @param  array<Image>  $attachments
     * @param  'low'|'medium'|'high'|null  $quality
     * @param  array<string, mixed>  $providerOptions
     */
    public function image(
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?string $model = null,
        ?int $timeout = null,
        array $providerOptions = [],
    ): ImageResponse {
        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultImageModel();

        $prompt = new ImagePrompt($prompt, $attachments, $size, $quality, $this, $model, $timeout, $providerOptions);

        if (Ai::imagesAreFaked()) {
            Ai::recordImageGeneration($prompt);
        }

        $this->events->dispatch(new GeneratingImage(
            $invocationId, $this, $model, $prompt,
        ));

        try {
            $response = $this->imageGateway()->generateImage(
                $this, $model, $prompt->prompt, $prompt->attachments->all(), $prompt->size, $prompt->quality, $timeout, $prompt->providerOptions,
            );
        } catch (Throwable $e) {
            $this->events->dispatch(new ImageFailed(
                $invocationId, $this, $model, $prompt, $e,
            ));

            throw $e;
        }

        $this->events->dispatch(new ImageGenerated(
            $invocationId, $this, $model, $prompt, $response,
        ));

        return $response;
    }
}
