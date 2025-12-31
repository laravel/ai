<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Responses\ImageResponse;

interface ImageProvider
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
    ): ImageResponse;

    /**
     * Get the provider's image gateway.
     */
    public function imageGateway(): ImageGateway;

    /**
     * Set the provider's image gateway.
     */
    public function useImageGateway(ImageGateway $gateway): self;

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string;

    /**
     * Get the default / normalized image options for the provider.
     */
    public function defaultImageOptions(?string $size = null, $quality = null): array;
}
