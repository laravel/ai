<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Providers\TextProvider;

class ZaiProvider extends Provider implements TextProvider
{
    use Concerns\GeneratesText;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'glm-4.7';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return 'glm-4.7-flash';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return 'glm-5';
    }

    // /**
    //  * Get the name of the default image model.
    //  */
    // public function defaultImageModel(): string
    // {
    //     return 'glm-image';
    // }

    // /**
    //  * Get the default / normalized image options for the provider.
    //  */
    // public function defaultImageOptions(?string $size = null, $quality = null): array
    // {
    //     return [
    //         'quality' => $quality ?? 'hd',
    //         'size' => match ($size) {
    //             '1:1' => '1280x1280',
    //             '4:3' => '1472x1088',
    //             '3:4' => '1088x1472',
    //             '16:9' => '1728x960',
    //             '9:16' => '960x1728',
    //             '3:2' => '1568x1056',
    //             '2:3' => '1056x1568',
    //             null => '1280x1280',
    //             default => $size,
    //         },
    //         'moderation' => 'low',
    //     ];
    // }
}
