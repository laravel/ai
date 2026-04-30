<?php

namespace Laravel\Ai\Gateway\AzureOpenAi;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\AzureOpenAi\Concerns\CreatesAzureOpenAiClient;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\OpenAi\Concerns\BuildsTextRequests;
use Laravel\Ai\Gateway\OpenAi\Concerns\HandlesTextStreaming;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsAttachments;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsMessages;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsTools;
use Laravel\Ai\Gateway\OpenAi\Concerns\ParsesTextResponses;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\TextResponse;

class AzureOpenAiGateway implements EmbeddingGateway, TextGateway
{
    use BuildsTextRequests;
    use CreatesAzureOpenAiClient;
    use HandlesFailoverErrors;
    use HandlesTextStreaming;
    use InvokesTools;
    use MapsAttachments;
    use MapsMessages;
    use MapsTools;
    use ParsesServerSentEvents;
    use ParsesTextResponses;

    public function __construct(protected Dispatcher $events)
    {
        $this->initializeToolCallbacks();
    }

    /**
     * {@inheritdoc}
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $body = $this->buildTextRequestBody(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
        );

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('responses', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, filled($schema), $tools, $schema, $options, $timeout);
    }

    /**
     * {@inheritdoc}
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        $body = $this->buildTextRequestBody(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
        );

        $body['stream'] = true;

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('responses', $body),
        );

        yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $tools,
            $schema,
            $options,
            $response->getBody(),
            0,
            null,
            $timeout,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
    ): EmbeddingsResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('embeddings', [
                'model' => $model,
                'input' => $inputs,
                'dimensions' => $dimensions,
            ]),
        );

        $data = $response->json();

        return new EmbeddingsResponse(
            collect($data['data'] ?? [])->pluck('embedding')->all(),
            $data['usage']['prompt_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function mapTool(Tool $tool): array
    {
        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $schemaArray = filled($schema)
            ? (new ObjectSchema($schema))->toSchema()
            : [];

        return array_filter([
            'type' => 'function',
            'name' => $this->getToolName($tool),
            'description' => (string) $tool->description(),
            'parameters' => filled($schemaArray) ? [
                'type' => 'object',
                'properties' => $schemaArray['properties'] ?? (object) [],
                'required' => $schemaArray['required'] ?? [],
            ] : null,
        ]);
    }
}
