<?php

namespace Laravel\Ai\Gateway\Anthropic;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use LogicException;

class AnthropicGateway implements Gateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesAnthropicClient;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\MapsMessages;
    use Concerns\MapsTools;
    use Concerns\ParsesTextResponses;
    use HandlesFailoverErrors;
    use InvokesTools;
    use ParsesServerSentEvents;

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
            fn () => $this->client($provider, $timeout)->post('messages', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse(
            $data,
            $provider,
            filled($schema),
            $tools,
            $schema,
            $options,
            $body,
            $timeout,
        );
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
                ->post('messages', $body),
        );

        yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $tools,
            $schema,
            $options,
            $response->getBody(),
            $body,
            0,
            null,
            $timeout,
        );
    }

    /**
     * Generate an image.
     *
     * @throws LogicException
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
        throw new LogicException('Anthropic does not support image generation.');
    }

    /**
     * Generate audio from the given text.
     *
     * @throws LogicException
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
    ): AudioResponse {
        throw new LogicException('Anthropic does not support audio generation.');
    }

    /**
     * Generate text from the given audio.
     *
     * @throws LogicException
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
        array $providerOptions = [],
    ): TranscriptionResponse {
        throw new LogicException('Anthropic does not support transcription generation.');
    }

    /**
     * Generate embeddings for the given inputs.
     *
     * @throws LogicException
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        throw new LogicException('Anthropic does not support embedding generation.');
    }

    /**
     * {@inheritdoc}
     */
    protected function overloadedStatusCodes(): array
    {
        return [529];
    }

    /**
     * {@inheritdoc}
     */
    protected function insufficientCreditPatterns(): array
    {
        return [
            'credit balance',
            'insufficient',
            'quota exceeded',
            'exceeded your current quota',
            'billing',
        ];
    }
}
