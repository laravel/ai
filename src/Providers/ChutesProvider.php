<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\ChutesImageGateway;

class ChutesProvider extends Provider implements ImageProvider, TextProvider
{
    use Concerns\GeneratesImages;
    use Concerns\GeneratesText;
    use Concerns\HasImageGateway;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['default'] ?? 'deepseek-ai/DeepSeek-V3';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['cheapest'] ?? 'unsloth/gemma-3-4b-it';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['smartest'] ?? 'deepseek-ai/DeepSeek-R1';
    }

    /**
     * Get the image gateway instance.
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ?? new ChutesImageGateway;
    }

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string
    {
        return $this->config['models']['image'] ?? 'FLUX.1-schnell';
    }

    /**
     * Get the default image generation options.
     *
     * @param  '3:2'|'2:3'|'1:1'|null  $size
     * @param  'low'|'medium'|'high'|null  $quality
     */
    public function defaultImageOptions(?string $size = null, $quality = null): array
    {
        [$width, $height] = match ($size) {
            '1:1' => [1024, 1024],
            '3:2' => [1536, 1024],
            '2:3' => [1024, 1536],
            default => [1024, 1024],
        };

        return [
            'width' => $width,
            'height' => $height,
            'steps' => match ($quality) {
                'low' => 5,
                'medium' => 10,
                'high' => 20,
                default => 10,
            },
            'guidance_scale' => 7.5,
        ];
    }
}
