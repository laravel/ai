<?php

namespace Laravel\Ai\Contracts\Gateway;

use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Responses\ImageResponse;

interface ImageGateway
{
    /**
     * Generate an image.
     *
     * @param  array<ImageFile>  $attachments
     * @param  string  $size
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
    ): ImageResponse;
}
