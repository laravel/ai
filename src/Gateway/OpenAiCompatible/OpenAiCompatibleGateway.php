<?php

namespace Laravel\Ai\Gateway\OpenAiCompatible;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;

class OpenAiCompatibleGateway implements EmbeddingGateway, StepTextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\MapsChatCompletionMessages;
    use Concerns\MapsChatCompletionTools;
    use Concerns\ParsesTextResponses;
    use Concerns\PerformsChatCompletionSteps;
    use CreatesClient;
    use HandlesFailoverErrors;
    use ParsesServerSentEvents;

    public function __construct(protected Dispatcher $events)
    {
        //
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
        array $providerOptions = [],
    ): EmbeddingsResponse {
        if (($providerOptions['encoding_format'] ?? 'float') !== 'float') {
            throw new InvalidArgumentException('This openai-compatible provider only supports float embedding responses.');
        }

        $body = array_merge($providerOptions, array_filter([
            'model' => $model,
            'input' => $inputs,
            'dimensions' => $dimensions ?: null,
        ]));

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('embeddings', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return new EmbeddingsResponse(
            $this->parseEmbeddings($data, count($inputs)),
            $data['usage']['prompt_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Parse the embedding vectors on an OpenAI-compatible response.
     *
     * @return array<int, array<int, float>>
     */
    protected function parseEmbeddings(array $data, int $expectedCount): array
    {
        $embeddings = (new Collection($data['data'] ?? []))->pluck('embedding');

        if ($embeddings->count() !== $expectedCount) {
            throw new AiException('OpenAI-compatible Error: [invalid_response] The response did not contain the expected number of embeddings.');
        }

        return $embeddings->map(function (mixed $embedding): array {
            if (! is_array($embedding) || $embedding === [] || array_filter($embedding, 'is_numeric') !== $embedding) {
                throw new AiException('OpenAI-compatible Error: [invalid_response] The response contained an invalid embedding vector.');
            }

            return array_map(floatval(...), $embedding);
        })->all();
    }

    /**
     * Get the stream options sent with a streaming Chat Completions request.
     */
    protected function streamOptions(Provider $provider): ?array
    {
        return $provider->additionalConfiguration()['stream_options'] ?? null;
    }

    /**
     * Get the base URL for the OpenAI-compatible API.
     */
    protected function baseUrl(Provider $provider): string
    {
        $url = $provider->additionalConfiguration()['url'] ?? null;

        if (blank($url)) {
            throw new InvalidArgumentException(
                "The [{$provider->name()}] openai-compatible provider requires a 'url' to be configured."
            );
        }

        return rtrim((string) $url, '/');
    }
}
