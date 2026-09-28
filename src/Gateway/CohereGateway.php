<?php

namespace Laravel\Ai\Gateway;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Cohere\Concerns\BuildsTextRequests;
use Laravel\Ai\Gateway\Cohere\Concerns\HandlesTextStreaming;
use Laravel\Ai\Gateway\Cohere\Concerns\MapsAttachments;
use Laravel\Ai\Gateway\Cohere\Concerns\ParsesEmbeddings;
use Laravel\Ai\Gateway\Cohere\Concerns\ParsesTextResponses;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionMessages;
use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionTools;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\Data\RerankingUsage;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\RerankingResponse;

class CohereGateway implements EmbeddingGateway, RerankingGateway, StepTextGateway
{
    use BuildsTextRequests;
    use Concerns\CreatesClient;
    use HandlesFailoverErrors;
    use HandlesTextStreaming;
    use MapsAttachments;
    use MapsChatCompletionMessages;
    use MapsChatCompletionTools;
    use ParsesEmbeddings;
    use ParsesServerSentEvents;
    use ParsesTextResponses;

    /**
     * Generate text for a single step in a conversation.
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $body = $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout ?? 60)->post('/chat', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, $model, filled($schema))->withRawResponse($response);
    }

    /**
     * Stream text for a single step in a conversation.
     */
    public function generateStreamStep(
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
        $body = $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        $body['stream'] = true;

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout ?? 60)
                ->withOptions(['stream' => true])
                ->post('/chat', $body),
        );

        return yield from $this->processTextStream($invocationId, $provider, $model, $response->getBody());
    }

    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param  string[]  $inputs
     * @param  array<string, mixed>  $providerOptions
     */
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
            fn () => $this->client($provider, $timeout)->post('/embed', array_merge(
                [
                    'input_type' => 'search_document',
                    'embedding_types' => ['float'],
                ],
                $providerOptions,
                [
                    'model' => $model,
                    'texts' => $inputs,
                ],
            )),
        );

        $data = $response->json();

        return new EmbeddingsResponse(
            $this->parseCohereEmbeddings($data['embeddings'] ?? []),
            new Usage($data['meta']['billed_units']['input_tokens'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Rerank the given documents based on their relevance to the query.
     *
     * @param  array<int, string>  $documents
     * @param  array<string, mixed>  $providerOptions
     */
    public function rerank(
        RerankingProvider $provider,
        string $model,
        array $documents,
        string $query,
        ?int $limit = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): RerankingResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('/rerank', array_merge($providerOptions, array_filter([
                'model' => $model,
                'query' => $query,
                'documents' => $documents,
                'top_n' => $limit,
            ]))),
        );

        $data = $response->json();

        $results = (new Collection($data['results']))->map(fn (array $result): RankedDocument => new RankedDocument(
            index: $result['index'],
            document: $documents[$result['index']],
            score: $result['relevance_score'],
        ))->all();

        return new RerankingResponse(
            $results,
            new RerankingUsage(
                inputTokens: $data['meta']['billed_units']['input_tokens'] ?? 0,
                searchUnits: $data['meta']['billed_units']['search_units'] ?? null,
            ),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Get an HTTP client for the Cohere API.
     */
    protected function client(EmbeddingProvider|RerankingProvider $provider, int $timeout = 30): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        return $this->createClient(
            $config['url'] ?? 'https://api.cohere.com/v2',
            [
                'Authorization' => 'Bearer '.$provider->providerCredentials()['key'],
                'Content-Type' => 'application/json',
            ],
            $config['headers'] ?? [],
            $timeout,
        );
    }
}
