<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\UniqueIdentifierGenerator;
use Laravel\Ai\Events\GeneratingImage;
use Laravel\Ai\Events\ImageGenerated;
use Laravel\Ai\Prompts\ImagePrompt;
use Laravel\Ai\Responses\ImageResponse;

trait GeneratesImages
{
    /**
     * Generate an image.
     *
     * @param  array<ImageAttachment>  $attachments
     * @param  '3:2'|'2:3'|'1:1'  $size
     * @param  'low'|'medium'|'high'  $quality
     */
    public function image(
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?string $model = null,
        ?int $timeout = null,
    ): ImageResponse {
        $invocationId = resolve(UniqueIdentifierGenerator::class)->generate();

        $model ??= $this->defaultImageModel();

        $prompt = new ImagePrompt($prompt, $attachments, $size, $quality, $this, $model);

        if (Ai::imagesAreFaked()) {
            Ai::recordImageGeneration($prompt);
        }

        $this->events->dispatch(new GeneratingImage(
            $invocationId, $this, $model, $prompt,
        ));

        return tap($this->imageGateway()->generateImage(
            $this, $model, $prompt->prompt, $prompt->attachments->all(), $prompt->size, $prompt->quality, $timeout,
        ), function (ImageResponse $response) use ($invocationId, $prompt, $model) {
            $this->events->dispatch(new ImageGenerated(
                $invocationId, $this, $model, $prompt, $response,
            ));
        });
    }
}
