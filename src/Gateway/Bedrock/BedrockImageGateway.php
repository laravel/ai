<?php

namespace Laravel\Ai\Gateway\Bedrock;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Gateway\Bedrock\Concerns\CreatesBedrockClient;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;
use Throwable;

class BedrockImageGateway implements ImageGateway
{
    use CreatesBedrockClient;
    use HandlesFailoverErrors;

    /**
     * Generate an image using AWS Bedrock.
     *
     * @param  array<Image>  $attachments
     * @param  '3:2'|'2:3'|'1:1'|null  $size
     * @param  'low'|'medium'|'high'|null  $quality
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
        $client = $this->createBedrockClient($provider, $timeout);
        $options = $provider->defaultImageOptions($size, $quality);

        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $client->invokeModel([
                    'modelId' => $model,
                    'contentType' => 'application/json',
                    'accept' => 'application/json',
                    'body' => json_encode($this->prepareImageRequestBody($model, $prompt, $size, $options)),
                ]),
            );
        } catch (Throwable $e) {
            throw BedrockException::toAiException($e, $provider->name(), $model);
        }

        $result = json_decode($response->get('body')->getContents(), true);

        return new ImageResponse(
            $this->parseImageResponse($model, $result),
            new Usage,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Prepare the request body for the given model family.
     *
     * @param  array{quality: string, size: string}  $options
     */
    protected function prepareImageRequestBody(string $model, string $prompt, ?string $size, array $options): array
    {
        [$width, $height] = $this->parseSize($size);
        $quality = $options['quality'];

        return match (true) {
            str_starts_with($model, 'stability.') => array_filter([
                'prompt' => $prompt,
                'aspect_ratio' => in_array($size, ['1:1', '2:3', '3:2'], true) ? $size : null,
                'output_format' => 'png',
            ]),
            str_starts_with($model, 'amazon.titan-image') => [
                'taskType' => 'TEXT_IMAGE',
                'textToImageParams' => ['text' => $prompt],
                'imageGenerationConfig' => [
                    'numberOfImages' => 1,
                    'quality' => $quality,
                    'height' => $height,
                    'width' => $width,
                    'cfgScale' => 7.0,
                ],
            ],
            str_starts_with($model, 'amazon.nova-canvas') => [
                'taskType' => 'TEXT_IMAGE',
                'textToImageParams' => ['text' => $prompt],
                'imageGenerationConfig' => [
                    'numberOfImages' => 1,
                    'quality' => $quality,
                    'width' => $width,
                    'height' => $height,
                ],
            ],
            default => ['prompt' => $prompt],
        };
    }

    /**
     * Parse the image response payload into GeneratedImage instances.
     */
    protected function parseImageResponse(string $model, array $result): Collection
    {
        if (str_starts_with($model, 'stability.')
            || str_starts_with($model, 'amazon.titan-image')
            || str_starts_with($model, 'amazon.nova-canvas')) {
            return (new Collection($result['images'] ?? []))
                ->map(fn ($image) => new GeneratedImage($image ?? '', 'image/png'));
        }

        return new Collection;
    }

    /**
     * Parse an aspect-ratio size into explicit [width, height] dimensions.
     *
     * @return array{0: int, 1: int}
     */
    protected function parseSize(?string $size): array
    {
        return match ($size) {
            '2:3' => [768, 1152],
            '3:2' => [1152, 768],
            default => [1024, 1024],
        };
    }
}
