<?php

namespace Laravel\Ai\Gateway\AzureOpenAi;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Gateway\AzureOpenAi\Concerns\CreatesAzureOpenAiClient;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\OpenAi\Concerns\BuildsTextRequests;
use Laravel\Ai\Gateway\OpenAi\Concerns\HandlesTextGeneration;
use Laravel\Ai\Gateway\OpenAi\Concerns\HandlesTextSteps;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsAttachments;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsMessages;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsTools;
use Laravel\Ai\Gateway\OpenAi\Concerns\ParsesTextResponses;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Tools\ToolNameResolver;
use LogicException;

class AzureOpenAiGateway implements EmbeddingGateway, ImageGateway, StepTextGateway
{
    use BuildsTextRequests;
    use CreatesAzureOpenAiClient;
    use HandlesFailoverErrors;
    use HandlesTextGeneration;
    use HandlesTextSteps;
    use MapsAttachments;
    use MapsMessages;
    use MapsTools;
    use ParsesServerSentEvents;
    use ParsesTextResponses;

    public function __construct(protected Dispatcher $events) {}

    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
        array $headers = [],
    ): EmbeddingsResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->withHeaders($headers)->post('embeddings', array_merge($providerOptions, [
                'model' => $model,
                'input' => $inputs,
                'dimensions' => $dimensions,
            ])),
        );

        $data = $response->json();

        return new EmbeddingsResponse(
            collect($data['data'] ?? [])->pluck('embedding')->all(),
            $data['usage']['prompt_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate an image.
     *
     * @param  array<Image>  $attachments
     * @param  '3:2'|'2:3'|'1:1'|null  $size
     * @param  'low'|'medium'|'high'|null  $quality
     *
     * @throws LogicException if attachments are passed; Azure OpenAI does not support image edits.
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
        array $headers = [],
    ): ImageResponse {
        if (filled($attachments)) {
            throw new LogicException('Azure OpenAI does not support image editing.');
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout ?? 120)->withHeaders($headers)->post('images/generations', [
                'model' => $model,
                'prompt' => $prompt,
                'moderation' => 'low',
                ...$provider->defaultImageOptions($size, $quality),
            ]),
        );

        $data = $response->json();

        return new ImageResponse(
            collect($data['data'] ?? [])->map(fn (array $image): GeneratedImage => new GeneratedImage(
                $image['b64_json'] ?? '',
                'image/png',
            )),
            $this->extractImageUsage($data),
            new Meta($provider->name(), $model),
        );
    }

    protected function extractImageUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];
        $inputTokens = $usage['input_tokens'] ?? 0;
        $cachedTokens = $usage['input_tokens_details']['cached_tokens'] ?? 0;

        return new Usage(
            promptTokens: $inputTokens - $cachedTokens,
            completionTokens: $usage['output_tokens'] ?? 0,
            cacheReadInputTokens: $cachedTokens,
        );
    }

    protected function mapTool(Tool $tool): array
    {
        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $schemaArray = filled($schema)
            ? (new ObjectSchema($schema))->toSchema()
            : [];

        return array_filter([
            'type' => 'function',
            'name' => ToolNameResolver::resolve($tool),
            'description' => (string) $tool->description(),
            'parameters' => filled($schemaArray) ? [
                'type' => 'object',
                'properties' => $schemaArray['properties'] ?? (object) [],
                'required' => $schemaArray['required'] ?? [],
            ] : null,
        ]);
    }
}
