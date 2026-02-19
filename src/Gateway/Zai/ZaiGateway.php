<?php

declare(strict_types=1);

namespace Laravel\Ai\Gateway\Zai;

use Closure;
use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\Concerns\HandlesRateLimiting;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Gateway\Zai\Concerns\AddsToolsToZaiRequests;
use Laravel\Ai\Gateway\Zai\Contracts\ServerSentEventsStreamParserInterface;
use Laravel\Ai\Gateway\Zai\Exceptions\ModelNotSupportedException;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use RuntimeException;

class ZaiGateway implements Gateway
{
    use AddsToolsToZaiRequests,
        HandlesRateLimiting;

    public const string API_URL = 'https://api.z.ai/api/paas/v4/chat/completions';

    public const int STREAM_CHUNK_SIZE = 8192;

    protected array $currentTools = [];

    protected array $accumulatedToolCalls = [];

    protected string $accumulatedReasoning = '';

    public function __construct(
        protected Dispatcher $events,
        protected ServerSentEventsStreamParserInterface $sseParser
    ) {
        $this->invokingToolCallback = fn () => true;
        $this->toolInvokedCallback = fn () => true;
    }

    /**
     * {@inheritdoc}
     *
     * @throws JsonException
     * @throws RequestException
     * @throws RateLimitedException
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
        $structured = ! empty($schema);

        $this->currentTools = $tools;

        $requestData = [
            'model' => $model,
            'messages' => $this->buildMessages($instructions, $messages),
            ...$this->buildOptions($options, $schema),
        ];

        $requestData = $this->addTools($requestData, $tools);

        if (! empty($tools)) {
            $this->validateToolCallingSupport($model);
        }

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $this->client($timeout, $provider->providerCredentials()['key'])
                ->post(self::API_URL, $requestData)
                ->throw()
                ->json()
        );

        $text = $response['choices'][0]['message']['content'] ?? '';

        [$toolCalls, $toolResults] = $this->processToolCalls($response);

        $messages = new Collection([
            new AssistantMessage($text, collect($toolCalls)),
        ]);

        return $structured
            ? new StructuredTextResponse(
                structured: json_decode($text, true),
                text: $text,
                usage: new Usage($response['usage']['prompt_tokens'], $response['usage']['completion_tokens']),
                meta: new Meta($provider->name(), $model)
            )
                ->withMessages($messages)
                ->withToolCallsAndResults(
                    toolCalls: new Collection($toolCalls),
                    toolResults: new Collection($toolResults)
                )
            : new TextResponse(
                text: $text,
                usage: new Usage($response['usage']['prompt_tokens'], $response['usage']['completion_tokens']),
                meta: new Meta($provider->name(), $model)
            )
                ->withMessages($messages)
                ->withToolCallsAndResults(
                    toolCalls: new Collection($toolCalls),
                    toolResults: new Collection($toolResults)
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
        $this->currentTools = $tools;
        $this->accumulatedToolCalls = [];
        $this->accumulatedReasoning = '';

        $requestData = [
            'stream' => true,
            'model' => $model,
            'messages' => $this->buildMessages($instructions, $messages),
            ...$this->buildOptions($options, $schema),
        ];

        $requestData = $this->addTools($requestData, $tools);

        if (! empty($tools)) {
            $this->validateToolCallingSupport($model);
            $requestData['tool_stream'] = true;
        }

        $client = $this->client($timeout, $provider->providerCredentials()['key'])
            ->withOptions([
                'stream' => true,
            ]);

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $client->post(self::API_URL, $requestData)
                ->throw()
        );

        yield from $this->processStreamChunks($response->toPsrResponse()->getBody(), $invocationId);
    }

    /**
     * {@inheritdoc}
     */
    public function onToolInvocation(Closure $invoking, Closure $invoked): self
    {
        $this->invokingToolCallback = $invoking;
        $this->toolInvokedCallback = $invoked;

        return $this;
    }

    /**
     * Validate model supports tool calling.
     * Tool calling supported on GLM-4.6, GLM-4.7, GLM-5 and later.
     */
    protected function validateToolCallingSupport(string $model): void
    {
        if (! preg_match("/glm-(\d+(?:\.\d+)?)/i", $model, $matches)) {
            throw ModelNotSupportedException::forTools($model);
        }

        $version = (float) $matches[1];

        if ($version < 4.6) {
            throw ModelNotSupportedException::forTools($model);
        }
    }

    protected function processToolCalls(array $response): array
    {
        $toolCalls = [];
        $toolResults = [];

        if (! isset($response['choices'][0]['message']['tool_calls'])) {
            return [$toolCalls, $toolResults];
        }

        $parsedToolCalls = array_map(
            fn ($tc) => ZaiTool::toLaravelToolCall($tc),
            $response['choices'][0]['message']['tool_calls']
        );

        foreach ($parsedToolCalls as $toolCall) {
            $toolCalls[] = new ToolCallData(
                $toolCall['id'],
                $toolCall['name'],
                $toolCall['arguments']
            );
        }

        $executionResults = $this->executeTools($parsedToolCalls);

        foreach ($executionResults as $result) {
            if ($result !== null) {
                $toolResults[] = new ToolResultData(
                    $result['toolCallId'],
                    $result['toolName'],
                    $result['args'],
                    $result['result']
                );
            }
        }

        return [$toolCalls, $toolResults];
    }

    /**
     * Accumulate tool call deltas across stream chunks.
     * This approach mirrors the official Z.AI Python SDK, which also accumulates tool call deltas and only processes them after the streaming loop completes.
     */
    protected function accumulateToolCalls(array $toolCallDeltas): void
    {
        foreach ($toolCallDeltas as $delta) {
            $index = $delta['index'] ?? 0;

            if (! isset($this->accumulatedToolCalls[$index])) {
                $this->accumulatedToolCalls[$index] = [
                    'id' => $delta['id'] ?? '',
                    'type' => $delta['type'] ?? 'function',
                    'function' => [
                        'name' => $delta['function']['name'] ?? '',
                        'arguments' => $delta['function']['arguments'] ?? '',
                    ],
                ];
            } else {
                $this->accumulatedToolCalls[$index]['function']['arguments'] .=
                    $delta['function']['arguments'] ?? '';
            }
        }
    }

    /**
     * Yield completed tool calls as ToolCall events and execute them.
     * Yields both ToolCall and ToolResult events.
     */
    protected function yieldCompletedToolCalls(string $invocationId): Generator
    {
        foreach ($this->accumulatedToolCalls as $toolCallData) {
            try {
                $argumentsString = $toolCallData['function']['arguments'];
                $arguments = json_decode($argumentsString, true, 512, JSON_THROW_ON_ERROR);

                if (! is_array($arguments)) {
                    continue;
                }

                $toolCall = new ToolCallData(
                    id: $toolCallData['id'],
                    name: $toolCallData['function']['name'],
                    arguments: $arguments
                );

                yield new ToolCall(
                    id: uniqid('tc_', true),
                    toolCall: $toolCall,
                    timestamp: time()
                )->withInvocationId($invocationId);

                $tool = $this->findToolByName($toolCallData['function']['name']);

                if ($tool) {
                    $result = $this->invokeTool($tool, $arguments);

                    yield new ToolResultEvent(
                        id: uniqid('tr_', true),
                        toolResult: new ToolResultData(
                            $toolCallData['id'],
                            $toolCallData['function']['name'],
                            $arguments,
                            $result
                        ),
                        successful: true,
                        error: null,
                        timestamp: time()
                    )->withInvocationId($invocationId);
                }
            } catch (JsonException) {
                continue;
            }
        }
    }

    protected function yieldStreamEnd(?string $messageId, int $totalPromptTokens, int $totalCompletionTokens, string $finishReason, string $invocationId): Generator
    {
        yield from $this->yieldCompletedToolCalls($invocationId);

        yield new StreamEnd(
            id: $messageId ?? uniqid('msg_', true),
            reason: $finishReason,
            usage: new Usage($totalPromptTokens, $totalCompletionTokens),
            timestamp: time()
        )->withInvocationId($invocationId);
    }

    protected function processStreamChunks($body, string $invocationId): Generator
    {
        $buffer = '';
        $messageId = null;
        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;
        $finishReason = 'stop';

        try {
            while (! $body->eof()) {
                $chunk = $body->read(self::STREAM_CHUNK_SIZE);

                if ($chunk === '') {
                    continue;
                }

                $buffer .= $chunk;
                $lastNewline = strrpos($buffer, "\n");

                if ($lastNewline === false) {
                    continue;
                }

                $completeData = substr($buffer, 0, $lastNewline + 1);
                $buffer = substr($buffer, $lastNewline + 1);

                foreach ($this->sseParser->parse($completeData) as $event) {
                    $result = yield from $this->processStreamEvent(
                        $event,
                        $messageId,
                        $totalPromptTokens,
                        $totalCompletionTokens,
                        $finishReason,
                        $invocationId
                    );

                    if ($result !== null) {
                        [
                            'messageId' => $messageId,
                            'totalPromptTokens' => $totalPromptTokens,
                            'totalCompletionTokens' => $totalCompletionTokens,
                            'finishReason' => $finishReason,
                        ] = $result;

                        if ($result['done']) {
                            return;
                        }
                    }
                }
            }

            // Process remaining buffer
            if ($buffer !== '') {
                foreach ($this->sseParser->parse($buffer) as $event) {
                    $result = yield from $this->processStreamEvent(
                        $event,
                        $messageId,
                        $totalPromptTokens,
                        $totalCompletionTokens,
                        $finishReason,
                        $invocationId
                    );

                    if ($result !== null) {
                        [
                            'messageId' => $messageId,
                            'totalPromptTokens' => $totalPromptTokens,
                            'totalCompletionTokens' => $totalCompletionTokens,
                            'finishReason' => $finishReason,
                        ] = $result;

                        if ($result['done']) {
                            return;
                        }
                    }
                }
            }

            if ($messageId !== null) {
                yield from $this->yieldStreamEnd($messageId, $totalPromptTokens, $totalCompletionTokens, $finishReason, $invocationId);
            }
        } finally {
            $this->accumulatedToolCalls = [];
        }
    }

    /**
     * Process a single SSE event and yield appropriate streaming events.
     *
     * @return Generator Updated state if changed, null otherwise
     */
    protected function processStreamEvent(
        array $event,
        ?string $messageId,
        int $totalPromptTokens,
        int $totalCompletionTokens,
        string $finishReason,
        string $invocationId
    ): Generator {
        switch ($event['type']) {
            case 'text_delta':
                yield new TextDelta(
                    id: $event['id'],
                    messageId: $event['messageId'],
                    delta: $event['delta'],
                    timestamp: $event['timestamp']
                )->withInvocationId($invocationId);

                return [
                    'messageId' => $event['messageId'],
                    'totalPromptTokens' => $totalPromptTokens,
                    'totalCompletionTokens' => $totalCompletionTokens,
                    'finishReason' => $finishReason,
                    'done' => false,
                ];

            case 'reasoning_delta':
                $this->accumulatedReasoning .= $event['delta'];
                yield new ReasoningDelta(
                    id: $event['id'],
                    reasoningId: $event['reasoningId'],
                    delta: $event['delta'],
                    timestamp: $event['timestamp']
                )->withInvocationId($invocationId);

                return [
                    'messageId' => $event['id'],
                    'totalPromptTokens' => $totalPromptTokens,
                    'totalCompletionTokens' => $totalCompletionTokens,
                    'finishReason' => $finishReason,
                    'done' => false,
                ];

            case 'tool_call_delta':
                $this->accumulateToolCalls([[
                    'index' => $event['index'],
                    'id' => $event['id'],
                    'function' => [
                        'name' => $event['name'],
                        'arguments' => $event['arguments'],
                    ],
                ]]);

                return null;

            case 'usage':
                return [
                    'messageId' => $messageId,
                    'totalPromptTokens' => $event['promptTokens'],
                    'totalCompletionTokens' => $event['completionTokens'],
                    'finishReason' => $event['finishReason'] ?? $finishReason,
                    'done' => false,
                ];

            case 'done':
                yield from $this->yieldStreamEnd($messageId, $totalPromptTokens, $totalCompletionTokens, $finishReason, $invocationId);

                return [
                    'messageId' => $messageId,
                    'totalPromptTokens' => $totalPromptTokens,
                    'totalCompletionTokens' => $totalCompletionTokens,
                    'finishReason' => $finishReason,
                    'done' => true,
                ];
        }

        return null;
    }

    protected function client(?int $timeout, string $token): PendingRequest
    {
        return Http::withToken($token)
            ->timeout($timeout ?? 30);
    }

    protected function buildMessages(?string $instructions, array $messages): array
    {
        if ($instructions) {
            $messages = [
                [
                    'role' => 'system',
                    'content' => $instructions,
                ],
                ...$messages,
            ];
        }

        foreach ($messages as $messageKey => $message) {
            if ($message instanceof Message) {
                $messages[$messageKey] = [
                    'role' => $message->role->value,
                    'content' => $message->content,
                ];
            }
        }

        return $messages;
    }

    protected function buildOptions(?TextGenerationOptions $options, ?array $schema = null): array
    {
        $params = [];

        if ($schema) {
            $params['response_format'] = [
                'type' => 'json_object',
            ];
        }

        if (! $options) {
            return $params;
        }

        if ($options->temperature !== null) {
            $params['temperature'] = $options->temperature;
        }

        if ($options->maxTokens !== null) {
            $params['max_tokens'] = $options->maxTokens;
        }

        return $params;
    }

    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
    ): AudioResponse {
        throw new RuntimeException('Zai has no API for generating audio.');
    }

    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions
    ): EmbeddingsResponse {
        throw new RuntimeException('Zai has no API for generating embeddings.');
    }

    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
    ): ImageResponse {
        throw new RuntimeException('Not supported yet.');
    }

    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
    ): TranscriptionResponse {
        throw new RuntimeException('Not supported yet.');
    }
}
