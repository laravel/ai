<?php

namespace Laravel\Ai\Gateway\Mistral;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Files\HasName;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionMessages;
use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionTools;
use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\PerformsChatCompletionSteps;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;

class MistralGateway implements EmbeddingGateway, StepTextGateway, TranscriptionGateway
{
    use Concerns\BuildsConversationRequests;
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesMistralClient;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\ParsesConversationResponses;
    use Concerns\ParsesTextResponses;
    use HandlesFailoverErrors;
    use MapsChatCompletionMessages;
    use MapsChatCompletionTools;
    use ParsesServerSentEvents;
    use PerformsChatCompletionSteps {
        generateTextStep as generateChatCompletionStep;
        generateStreamStep as generateChatCompletionStreamStep;
    }

    public function __construct(protected Dispatcher $events)
    {
        //
    }

    /**
     * Generate text for a single step, routing to the Conversations API when file search is requested.
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
        if ($this->wantsFileSearch($tools)) {
            return $this->generateConversationStep($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout);
        }

        return $this->generateChatCompletionStep($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext);
    }

    /**
     * Stream text for a single step, routing to the Conversations API when file search is requested.
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
        if ($this->wantsFileSearch($tools)) {
            return yield from $this->streamConversationStep($invocationId, $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout);
        }

        return yield from $this->generateChatCompletionStreamStep($invocationId, $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext);
    }

    /**
     * Build the request body for the current text generation step.
     */
    protected function buildStepBody(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        StepContext $stepContext,
    ): array {
        return $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);
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
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('embeddings', array_merge($providerOptions, [
                'model' => $model,
                'input' => $inputs,
            ])),
        );

        $data = $response->json();

        return new EmbeddingsResponse(
            collect($data['data'] ?? [])->pluck('embedding')->all(),
            $data['usage']['total_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
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
    ): TranscriptionResponse {
        $params = ['model' => $model];

        if ($diarize) {
            $params['diarize'] = true;
            $params['timestamp_granularities'] = ['segment'];
        } elseif ($language) {
            $params['language'] = $language;
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->attach('file', $audio->content(), $this->audioFilename($audio), array_filter(['Content-Type' => $audio->mimeType()]))
                ->post('audio/transcriptions', $this->multipartParams(array_merge($providerOptions, $params))),
        );

        $data = $response->json();

        return new TranscriptionResponse(
            $data['text'] ?? '',
            collect($data['segments'] ?? [])->map(fn (array $segment) => new TranscriptionSegment(
                $segment['text'] ?? '',
                $segment['speaker_id'] ?? '',
                $segment['start'] ?? 0,
                $segment['end'] ?? 0,
            )),
            new Usage(
                $data['usage']['prompt_tokens'] ?? 0,
                $data['usage']['completion_tokens'] ?? 0,
            ),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Convert request parameters into multipart parts, expanding array values.
     *
     * @param  array<string, mixed>  $params
     * @return array<int, array{name: string, contents: scalar}>
     */
    protected function multipartParams(array $params): array
    {
        $parts = [];

        foreach ($params as $name => $value) {
            foreach (is_array($value) ? array_values($value) : [$value] as $item) {
                $parts[] = ['name' => $name, 'contents' => $item];
            }
        }

        return $parts;
    }

    /**
     * Determine the appropriate filename for the audio file based on its MIME type.
     */
    protected function audioFilename(TranscribableAudio $audio): string
    {
        if ($audio instanceof HasName && $audio->name()) {
            return $audio->name();
        }

        $extension = match ($audio->mimeType()) {
            'audio/webm' => 'webm',
            'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mpga' => 'mpga',
            default => 'mp3',
        };

        return "audio.{$extension}";
    }

    /**
     * Determine if the given tools require the Conversations API.
     */
    protected function wantsFileSearch(array $tools): bool
    {
        return (new Collection($tools))->contains(fn ($tool) => $tool instanceof FileSearch);
    }

    /**
     * Generate text for a single step via the Conversations API.
     */
    protected function generateConversationStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
    ): StepResponse {
        $body = $this->buildConversationRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('conversations', $body),
        );

        $data = $response->json();

        $this->validateConversationResponse($data);

        return $this->parseConversationResponse($data, $provider, $model, filled($schema));
    }

    /**
     * Stream text for a single step via the Conversations API.
     *
     * The Conversations request executes without streaming; the resulting
     * text is emitted as a single delta.
     */
    protected function streamConversationStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
    ): Generator {
        $step = $this->generateConversationStep($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout);

        $messageId = $this->generateEventId();

        yield (new StreamStart(
            $this->generateEventId(), $provider->name(), $step->meta->model, time(),
        ))->withInvocationId($invocationId);

        if (filled($step->text)) {
            yield (new TextStart($this->generateEventId(), $messageId, time()))->withInvocationId($invocationId);
            yield (new TextDelta($this->generateEventId(), $messageId, $step->text, time()))->withInvocationId($invocationId);
            yield (new TextEnd($this->generateEventId(), $messageId, time()))->withInvocationId($invocationId);
        }

        foreach ($step->toolCalls as $toolCall) {
            yield (new ToolCallEvent($this->generateEventId(), $toolCall, time()))->withInvocationId($invocationId);
        }

        return $step;
    }
}
