<?php

namespace Laravel\Ai\Gateway\OpenAiCompatible;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\Concerns\ResolvesAudioFilenames;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\TranscriptionResponse;

class OpenAiCompatibleGateway implements EmbeddingGateway, StepTextGateway, TranscriptionGateway
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
    use ResolvesAudioFilenames;

    public function __construct(protected Dispatcher $events)
    {
        //
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<string, mixed>  $providerOptions
     * @param  array<string, string>  $headers
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
        array $headers = [],
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
            fn () => $this->client($provider, $timeout)->withHeaders($headers)->post('embeddings', $body),
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
     * {@inheritdoc}
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
        array $providerOptions = [],
        array $headers = [],
    ): TranscriptionResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->withHeaders($headers)
                ->attach('file', $audio->content(), $this->audioFilename($audio), array_filter(['Content-Type' => $audio->mimeType()]))
                ->post('audio/transcriptions', array_merge(array_filter([
                    'model' => $model,
                    'language' => $language,
                    'response_format' => $diarize ? 'diarized_json' : 'json',
                ]), $providerOptions)),
        );

        $data = $response->json();

        return new TranscriptionResponse(
            $data['text'] ?? '',
            collect($data['segments'] ?? [])->map(fn (array $segment): TranscriptionSegment => new TranscriptionSegment(
                $segment['text'] ?? '',
                $segment['speaker'] ?? '',
                $segment['start'] ?? 0,
                $segment['end'] ?? 0,
            )),
            new Usage(
                Arr::get($data, 'usage.input_tokens') ?? Arr::get($data, 'usage.prompt_tokens', 0),
                Arr::get($data, 'usage.output_tokens') ?? Arr::get($data, 'usage.completion_tokens', 0),
            ),
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
