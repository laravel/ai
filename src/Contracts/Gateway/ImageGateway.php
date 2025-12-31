<?php

namespace Laravel\Ai\Contracts\Gateway;

use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Messages\Attachments\Image as ImageAttachment;
use Laravel\Ai\Responses\ImageResponse;

interface ImageGateway
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
    ): ImageResponse;
}
