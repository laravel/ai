<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Gateway\ModelsLabImageGateway;
use Laravel\Ai\Responses\ImageResponse;

class ModelsLabProvider extends Provider implements ImageProvider
{
    use Concerns\HasImageGateway;
    use Concerns\GeneratesImages;

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string
    {
        return 'flux';
    }

    /**
     * Generate an image.
     */
    public function image(
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?string $model = null,
        ?int $timeout = null,
    ): ImageResponse {
        $model = $model ?? $this->defaultImageModel();

        return $this->generateImage(
            prompt: $prompt,
            model: $model,
            options: $this->defaultImageOptions($size, $quality),
            attachments: $attachments,
            timeout: $timeout,
        );
    }

    /**
     * Get the provider's image gateway.
     */
    public function imageGateway(): ImageGateway
    {
        return new ModelsLabImageGateway(
            apiKey: $this->apiKey,
            baseUrl: $this->baseUrl ?? 'https://modelslab.com/api/v6',
        );
    }

    /**
     * Get the default / normalized image options for the provider.
     */
    public function defaultImageOptions(?string $size = null, $quality = null): array
    {
        return array_filter([
            'model' => $this->defaultImageModel(),
        ]);
    }
}
