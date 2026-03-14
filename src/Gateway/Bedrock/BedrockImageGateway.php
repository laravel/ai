<?php

namespace Laravel\Ai\Gateway\Bedrock;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Gateway\Concerns\HandlesRateLimiting;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;

class BedrockImageGateway implements ImageGateway
{
    use HandlesRateLimiting;

    /**
     * Generate an image using AWS Bedrock.
     *
     * @param  array<ImageFile>  $attachments
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
        ?int $timeout = null,
    ): ImageResponse {
        $client = $this->createBedrockClient($provider, $timeout);

        // Prepare request body based on model
        $requestBody = $this->prepareImageRequestBody($model, $prompt, $size, $quality);

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $client->invokeModel([
                'modelId' => $model,
                'contentType' => 'application/json',
                'accept' => 'application/json',
                'body' => json_encode($requestBody),
            ])
        );

        $result = json_decode($response->get('body')->getContents(), true);

        // Parse response based on model
        $images = $this->parseImageResponse($model, $result);

        return new ImageResponse(
            $images,
            new Usage,
            new Meta($provider->name(), $model)
        );
    }

    /**
     * Prepare the request body based on the model.
     */
    protected function prepareImageRequestBody(string $model, string $prompt, ?string $size, ?string $quality): array
    {
        // Stability AI models (Stable Diffusion, Stable Image)
        if (str_starts_with($model, 'stability.')) {
            [$width, $height] = $this->parseSizeForStability($size);

            return [
                'text_prompts' => [
                    ['text' => $prompt, 'weight' => 1.0],
                ],
                'cfg_scale' => 7.0,
                'steps' => $quality === 'high' ? 50 : 30,
                'width' => $width,
                'height' => $height,
            ];
        }

        // Amazon Titan Image Generator
        if (str_starts_with($model, 'amazon.titan-image')) {
            [$width, $height] = $this->parseSizeForTitan($size);

            return [
                'textToImageParams' => [
                    'text' => $prompt,
                ],
                'taskType' => 'TEXT_IMAGE',
                'imageGenerationConfig' => [
                    'numberOfImages' => 1,
                    'quality' => $quality ?? 'standard',
                    'height' => $height,
                    'width' => $width,
                    'cfgScale' => 7.0,
                ],
            ];
        }

        // Amazon Nova Canvas
        if (str_starts_with($model, 'amazon.nova-canvas')) {
            [$width, $height] = $this->parseSizeForNova($size);

            return [
                'taskType' => 'TEXT_IMAGE',
                'textToImageParams' => [
                    'text' => $prompt,
                ],
                'imageGenerationConfig' => [
                    'numberOfImages' => 1,
                    'quality' => $quality ?? 'standard',
                    'width' => $width,
                    'height' => $height,
                ],
            ];
        }

        // Default format
        return [
            'prompt' => $prompt,
        ];
    }

    /**
     * Parse the image response based on the model.
     */
    protected function parseImageResponse(string $model, array $result): Collection
    {
        $images = [];

        // Stability AI models
        if (str_starts_with($model, 'stability.')) {
            if (isset($result['artifacts']) && is_array($result['artifacts'])) {
                foreach ($result['artifacts'] as $artifact) {
                    $images[] = new GeneratedImage(
                        $artifact['base64'] ?? '',
                        'image/png'
                    );
                }
            }
        }

        // Amazon Titan Image Generator
        if (str_starts_with($model, 'amazon.titan-image')) {
            if (isset($result['images']) && is_array($result['images'])) {
                foreach ($result['images'] as $image) {
                    $images[] = new GeneratedImage(
                        $image ?? '',
                        'image/png'
                    );
                }
            }
        }

        // Amazon Nova Canvas
        if (str_starts_with($model, 'amazon.nova-canvas')) {
            if (isset($result['images']) && is_array($result['images'])) {
                foreach ($result['images'] as $image) {
                    $images[] = new GeneratedImage(
                        $image ?? '',
                        'image/png'
                    );
                }
            }
        }

        return new Collection($images);
    }

    /**
     * Parse size parameter for Stability AI models.
     */
    protected function parseSizeForStability(?string $size): array
    {
        return match ($size) {
            '1:1' => [1024, 1024],
            '2:3' => [768, 1152],
            '3:2' => [1152, 768],
            default => [1024, 1024],
        };
    }

    /**
     * Parse size parameter for Titan models.
     */
    protected function parseSizeForTitan(?string $size): array
    {
        return match ($size) {
            '1:1' => [1024, 1024],
            '2:3' => [768, 1152],
            '3:2' => [1152, 768],
            default => [1024, 1024],
        };
    }

    /**
     * Parse size parameter for Nova models.
     */
    protected function parseSizeForNova(?string $size): array
    {
        return match ($size) {
            '1:1' => [1024, 1024],
            '2:3' => [768, 1152],
            '3:2' => [1152, 768],
            default => [1024, 1024],
        };
    }

    /**
     * Create a Bedrock Runtime client.
     */
    protected function createBedrockClient(ImageProvider $provider, ?int $timeout = null): BedrockRuntimeClient
    {
        $credentials = $provider->providerCredentials();
        $config = $provider->additionalConfiguration();

        $clientConfig = [
            'region' => $config['region'] ?? 'us-east-1',
            'version' => '2023-09-30',
        ];

        if ($timeout) {
            $clientConfig['http'] = ['timeout' => $timeout];
        }

        // Handle different authentication methods
        if (! empty($credentials['bearer_token'])) {
            $clientConfig['credentials'] = [
                'token' => $credentials['bearer_token'],
            ];
        } elseif (! empty($credentials['access_key_id']) && ! empty($credentials['secret_access_key'])) {
            $clientConfig['credentials'] = [
                'key' => $credentials['access_key_id'],
                'secret' => $credentials['secret_access_key'],
            ];

            if (! empty($credentials['session_token'])) {
                $clientConfig['credentials']['token'] = $credentials['session_token'];
            }
        } elseif ($config['use_default_credential_provider'] ?? true) {
            // Use AWS default credential chain
        }

        return new BedrockRuntimeClient($clientConfig);
    }
}
