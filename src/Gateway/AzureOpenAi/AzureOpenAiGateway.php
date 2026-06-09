<?php

namespace Laravel\Ai\Gateway\AzureOpenAi;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Gateway\TurnTextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Gateway\AzureOpenAi\Concerns\CreatesAzureOpenAiClient;
use Laravel\Ai\Gateway\Concerns\DelegatesToTextGenerationLoop;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\OpenAi\Concerns\BuildsTextRequests;
use Laravel\Ai\Gateway\OpenAi\Concerns\HandlesTextStreaming;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsAttachments;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsMessages;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsTools;
use Laravel\Ai\Gateway\OpenAi\Concerns\ParsesTextResponses;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Gateway\TurnResponse;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Tools\ToolNameResolver;
use LogicException;

class AzureOpenAiGateway implements EmbeddingGateway, ImageGateway, TextGateway, TurnTextGateway
{
    use BuildsTextRequests;
    use CreatesAzureOpenAiClient;
    use DelegatesToTextGenerationLoop;
    use HandlesFailoverErrors;
    use HandlesTextStreaming;
    use MapsAttachments;
    use MapsMessages;
    use MapsTools;
    use ParsesServerSentEvents;
    use ParsesTextResponses;

    public function __construct(protected Dispatcher $events) {}

    public function handleTurn(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): TurnResponse {
        $body = $this->buildTurnBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('responses', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, filled($schema));
    }

    public function streamTurn(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $body = $this->buildTurnBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);
        $body['stream'] = true;

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('responses', $body),
        );

        yield from $this->processTextStream($invocationId, $provider, $model, $response->getBody());
    }

    protected function buildTurnBody(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        StepContext $stepContext,
    ): array {
        return $stepContext->continuationToken && ! $this->isStateless($provider)
            ? $this->buildContinuationBody($stepContext->continuationToken, $model, $messages, $tools, $provider, $schema, $options)
            : $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);
    }

    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('embeddings', array_merge($providerOptions, [
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
    ): ImageResponse {
        if (filled($attachments)) {
            throw new LogicException('Azure OpenAI does not support image editing.');
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout ?? 120)->post('images/generations', [
                'model' => $model,
                'prompt' => $prompt,
                'moderation' => 'low',
                ...$provider->defaultImageOptions($size, $quality),
            ]),
        );

        $data = $response->json();

        return new ImageResponse(
            collect($data['data'] ?? [])->map(fn (array $image) => new GeneratedImage(
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
