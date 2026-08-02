<?php

namespace Laravel\Ai\Gateway\OpenAiCompatible;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;

class OpenAiCompatibleGateway implements EmbeddingGateway, StepTextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesOpenAiCompatibleClient;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\MapsChatCompletionMessages;
    use Concerns\MapsChatCompletionTools;
    use Concerns\ParsesTextResponses;
    use Concerns\PerformsChatCompletionSteps;
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
            (new Collection($data['data'] ?? []))->pluck('embedding')->all(),
            $data['usage']['prompt_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Get the stream options sent with a streaming Chat Completions request.
     */
    protected function streamOptions(Provider $provider): ?array
    {
        return $provider->additionalConfiguration()['stream_options'] ?? null;
    }
}
