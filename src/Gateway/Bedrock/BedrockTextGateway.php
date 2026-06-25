<?php

namespace Laravel\Ai\Gateway\Bedrock;

use Generator;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\Bedrock\Concerns\CreatesBedrockClient;
use Laravel\Ai\Gateway\Bedrock\Concerns\MapsAttachments;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Tools\ToolNameResolver;
use stdClass;
use Throwable;

class BedrockTextGateway implements EmbeddingGateway, StepTextGateway
{
    use CreatesBedrockClient;
    use HandlesFailoverErrors;
    use MapsAttachments;

    protected const STRUCTURED_OUTPUT_TOOL = 'structured_output';

    public function __construct()
    {
        //
    }

    /**
     * Generate text for a single Converse step.
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
        $client = $this->createBedrockClient($provider, $timeout);

        $parameters = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $client->converse($parameters),
            );

            $result = $response->toArray();
        } catch (Throwable $e) {
            throw BedrockException::toAiException($e, $provider->name(), $model);
        }

        return $this->parseTextResponse($result, $provider, $model, filled($schema));
    }

    /**
     * Stream text for a single Converse step.
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
        $client = $this->createBedrockClient($provider, $timeout);

        $parameters = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $client->converseStream($parameters),
            );
        } catch (Throwable $e) {
            throw BedrockException::toAiException($e, $provider->name(), $model);
        }

        return yield from $this->processTextStream($invocationId, $provider, $model, $response['stream'], filled($schema));
    }

    /**
     * Build the Converse request parameters for the current text generation step.
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
        $conversationMessages = $this->formatMessages($messages);
        $schemaTools = $schema ? $this->buildSchemaTools($schema, $tools) : null;
        $formattedTools = $schemaTools === null && ! empty($tools) ? $this->formatTools($tools) : null;

        return $this->buildConverseParameters(
            $model,
            $instructions,
            $conversationMessages,
            $schemaTools,
            $formattedTools,
            empty($tools),
            $options,
            isFinalStep: $stepContext->isFinalStep,
        );
    }

    /**
     * Parse a single Converse response into a step response.
     */
    protected function parseTextResponse(array $result, TextProvider $provider, string $model, bool $structured): StepResponse
    {
        $usage = new Usage(
            promptTokens: $result['usage']['inputTokens'] ?? 0,
            completionTokens: $result['usage']['outputTokens'] ?? 0,
            cacheWriteInputTokens: $result['usage']['cacheWriteInputTokens'] ?? 0,
            cacheReadInputTokens: $result['usage']['cacheReadInputTokens'] ?? 0,
        );

        $output = '';
        $toolCalls = [];
        $providerContentBlocks = [];
        $structuredOutput = null;

        foreach ($result['output']['message']['content'] ?? [] as $block) {
            $providerContentBlocks[] = $block;

            if (isset($block['text'])) {
                $output .= $block['text'];

                continue;
            }

            if (! isset($block['toolUse'])) {
                continue;
            }

            if ($structured && $block['toolUse']['name'] === self::STRUCTURED_OUTPUT_TOOL) {
                $structuredOutput = json_encode($block['toolUse']['input'] ?? []);

                continue;
            }

            $toolCalls[] = new ToolCall(
                $block['toolUse']['toolUseId'],
                $block['toolUse']['name'],
                $block['toolUse']['input'] ?? [],
            );
        }

        $finishReason = $this->extractFinishReason($result);

        if (empty($toolCalls) && $structured && $finishReason === FinishReason::ToolCalls) {
            $finishReason = FinishReason::Stop;
        }

        return new StepResponse(
            text: $structuredOutput ?? $output,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
            usage: $usage,
            meta: new Meta($provider->name(), $model),
            structured: $structuredOutput !== null ? $this->decodeStructuredOutput($structuredOutput) : null,
            providerContentBlocks: $providerContentBlocks,
        );
    }

    /**
     * Decode the structured output JSON, falling back to an empty array on failure.
     */
    protected function decodeStructuredOutput(string $json): array
    {
        $structured = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE ? $structured : [];
    }

    /**
     * Stream a single Converse step, returning the parsed step response.
     *
     * @return Generator<int, StreamEvent, mixed, StepResponse>
     */
    protected function processTextStream(
        string $invocationId,
        TextProvider $provider,
        string $model,
        $stream,
        bool $structured,
    ): Generator {
        $messageId = (string) Str::uuid();
        $timestamp = time();
        $totalUsage = new Usage;

        yield (new StreamStart(
            (string) Str::uuid(),
            $provider->name(),
            $model,
            $timestamp,
        ))->withInvocationId($invocationId);

        $assistantText = '';
        $pendingToolCalls = [];
        $toolCalls = [];
        $structuredOutput = null;
        $currentBlockIndex = null;
        $currentBlockType = '';
        $responseContent = [];
        $reasoningId = '';
        $textId = '';
        $currentText = '';
        $currentReasoningText = '';
        $currentReasoningSignature = '';
        $currentReasoningRedacted = '';
        $stopReason = 'stop';

        $emitTextStart = function () use (&$textId, $invocationId, $timestamp) {
            if ($textId !== '') {
                return null;
            }

            $textId = (string) Str::uuid();

            return (new TextStart(
                (string) Str::uuid(),
                $textId,
                $timestamp,
            ))->withInvocationId($invocationId);
        };

        $emitReasoningStart = function () use (&$reasoningId, $invocationId, $timestamp) {
            if ($reasoningId !== '') {
                return null;
            }

            $reasoningId = (string) Str::uuid();

            return (new ReasoningStart(
                (string) Str::uuid(),
                $reasoningId,
                $timestamp,
            ))->withInvocationId($invocationId);
        };

        foreach ($stream as $event) {
            if (isset($event['contentBlockStart'])) {
                $currentBlockIndex = $event['contentBlockStart']['contentBlockIndex'] ?? 0;
                $start = $event['contentBlockStart']['start'] ?? [];
                $currentBlockType = isset($start['toolUse']) ? 'toolUse' : '';

                if (isset($start['toolUse'])) {
                    $pendingToolCalls[$currentBlockIndex] = [
                        'id' => $start['toolUse']['toolUseId'] ?? '',
                        'name' => $start['toolUse']['name'] ?? '',
                        'input' => '',
                    ];
                }

                continue;
            }

            if (isset($event['contentBlockDelta'])) {
                $index = $event['contentBlockDelta']['contentBlockIndex'] ?? $currentBlockIndex;
                $delta = $event['contentBlockDelta']['delta'] ?? [];

                if (isset($delta['text'])) {
                    $currentBlockType = 'text';

                    if ($emittedEvent = $emitTextStart()) {
                        yield $emittedEvent;
                    }

                    $assistantText .= $delta['text'];
                    $currentText .= $delta['text'];

                    yield (new TextDelta(
                        (string) Str::uuid(),
                        $textId,
                        $delta['text'],
                        $timestamp,
                    ))->withInvocationId($invocationId);
                } elseif (isset($delta['reasoningContent']['text'])) {
                    $currentBlockType = 'reasoning';

                    if ($emittedEvent = $emitReasoningStart()) {
                        yield $emittedEvent;
                    }

                    $currentReasoningText .= $delta['reasoningContent']['text'];

                    yield (new ReasoningDelta(
                        (string) Str::uuid(),
                        $reasoningId,
                        $delta['reasoningContent']['text'],
                        $timestamp,
                    ))->withInvocationId($invocationId);
                } elseif (isset($delta['reasoningContent']['signature'])) {
                    $currentBlockType = 'reasoning';

                    if ($emittedEvent = $emitReasoningStart()) {
                        yield $emittedEvent;
                    }

                    $currentReasoningSignature .= $delta['reasoningContent']['signature'];
                } elseif (isset($delta['reasoningContent']['redactedContent'])) {
                    $currentBlockType = 'reasoning';

                    if ($emittedEvent = $emitReasoningStart()) {
                        yield $emittedEvent;
                    }

                    $currentReasoningRedacted .= $delta['reasoningContent']['redactedContent'];
                } elseif (isset($delta['toolUse']['input'], $pendingToolCalls[$index])) {
                    $pendingToolCalls[$index]['input'] .= $delta['toolUse']['input'];
                }

                continue;
            }

            if (isset($event['contentBlockStop'])) {
                $index = $event['contentBlockStop']['contentBlockIndex'] ?? $currentBlockIndex;

                if ($currentBlockType === 'reasoning') {
                    if ($currentReasoningRedacted !== '') {
                        $responseContent[$index] = [
                            'reasoningContent' => [
                                'redactedContent' => $currentReasoningRedacted,
                            ],
                        ];
                    } else {
                        $responseContent[$index] = [
                            'reasoningContent' => [
                                'reasoningText' => [
                                    'text' => $currentReasoningText,
                                    'signature' => $currentReasoningSignature,
                                ],
                            ],
                        ];
                    }

                    yield (new ReasoningEnd(
                        (string) Str::uuid(),
                        $reasoningId,
                        $timestamp,
                    ))->withInvocationId($invocationId);

                    $currentReasoningText = '';
                    $currentReasoningSignature = '';
                    $currentReasoningRedacted = '';
                    $reasoningId = '';
                } elseif ($currentBlockType === 'text' && $textId !== '') {
                    $responseContent[$index] = ['text' => $currentText];

                    yield (new TextEnd(
                        (string) Str::uuid(),
                        $textId,
                        $timestamp,
                    ))->withInvocationId($invocationId);

                    $currentText = '';
                    $textId = '';
                } elseif ($currentBlockType === 'toolUse' && isset($pendingToolCalls[$index])) {
                    $pending = $pendingToolCalls[$index];
                    $arguments = json_decode($pending['input'] !== '' ? $pending['input'] : '{}', true) ?? [];

                    if ($structured && $pending['name'] === self::STRUCTURED_OUTPUT_TOOL) {
                        $structuredOutput = json_encode($arguments);
                    } else {
                        $toolCall = new ToolCall($pending['id'], $pending['name'], $arguments);
                        $toolCalls[] = $toolCall;
                        $responseContent[$index] = [
                            'toolUse' => [
                                'toolUseId' => $toolCall->id,
                                'name' => $toolCall->name,
                                'input' => $arguments,
                            ],
                        ];

                        yield (new ToolCallEvent(
                            (string) Str::uuid(),
                            $toolCall,
                            $timestamp,
                        ))->withInvocationId($invocationId);
                    }

                    unset($pendingToolCalls[$index]);
                }

                $currentBlockType = '';

                continue;
            }

            if (isset($event['messageStop'])) {
                $stopReason = $event['messageStop']['stopReason'] ?? 'stop';

                continue;
            }

            if (isset($event['metadata']['usage'])) {
                $totalUsage = $totalUsage->add(new Usage(
                    promptTokens: $event['metadata']['usage']['inputTokens'] ?? 0,
                    completionTokens: $event['metadata']['usage']['outputTokens'] ?? 0,
                    cacheWriteInputTokens: $event['metadata']['usage']['cacheWriteInputTokens'] ?? 0,
                    cacheReadInputTokens: $event['metadata']['usage']['cacheReadInputTokens'] ?? 0,
                ));
            }
        }

        if ($structuredOutput !== null) {
            yield (new TextDelta(
                (string) Str::uuid(),
                $messageId,
                $structuredOutput,
                $timestamp,
            ))->withInvocationId($invocationId);
        }

        $finishReason = $this->extractFinishReason(['stopReason' => $stopReason]);

        if (empty($toolCalls) && $structured && $finishReason === FinishReason::ToolCalls) {
            $finishReason = FinishReason::Stop;
        }

        return new StepResponse(
            text: $assistantText,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
            usage: $totalUsage,
            meta: new Meta($provider->name(), $model),
            structured: $structuredOutput !== null ? $this->decodeStructuredOutput($structuredOutput) : null,
            providerContentBlocks: array_values($responseContent),
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
        array $providerOptions = [],
    ): EmbeddingsResponse {
        $client = $this->createBedrockClient($provider, $timeout);

        if (str_starts_with($model, 'cohere.')) {
            return $this->generateCohereEmbeddings($provider, $model, $client, $inputs, $providerOptions);
        }

        $embeddings = [];
        $totalTokens = 0;

        foreach ($inputs as $input) {
            try {
                $response = $this->withErrorHandling(
                    $provider->name(),
                    fn () => $client->invokeModel([
                        'modelId' => $model,
                        'contentType' => 'application/json',
                        'accept' => 'application/json',
                        'body' => json_encode(array_merge($providerOptions, [
                            'inputText' => $input,
                            'dimensions' => $dimensions,
                        ])),
                    ]),
                );

                $result = json_decode($response->get('body')->getContents(), true);
            } catch (Throwable $e) {
                throw BedrockException::toAiException($e, $provider->name(), $model);
            }

            if (isset($result['embedding'])) {
                $embeddings[] = $result['embedding'];
            }

            $totalTokens += $result['inputTextTokenCount'] ?? 0;
        }

        return new EmbeddingsResponse(
            $embeddings,
            $totalTokens,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate embeddings using a Cohere Bedrock model in a single batched call.
     *
     * @param  array<string>  $inputs
     */
    protected function generateCohereEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        $client,
        array $inputs,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $client->invokeModel([
                    'modelId' => $model,
                    'contentType' => 'application/json',
                    'accept' => 'application/json',
                    'body' => json_encode(array_merge(
                        ['input_type' => 'search_document'],
                        $providerOptions,
                        ['texts' => array_values($inputs)],
                    )),
                ]),
            );

            $result = json_decode($response->get('body')->getContents(), true);
        } catch (Throwable $e) {
            throw BedrockException::toAiException($e, $provider->name(), $model);
        }

        $embeddings = array_values(array_filter(
            $result['embeddings'] ?? [],
            fn ($vector) => is_array($vector),
        ));

        return new EmbeddingsResponse(
            $embeddings,
            0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Resolve the maximum number of steps for the given tools and options.
     *
     * @param  array<Tool>  $tools
     */
    protected function resolveMaxSteps(array $tools, ?TextGenerationOptions $options): int
    {
        if (empty($tools)) {
            return 1;
        }

        return (int) ($options?->maxSteps ?? round(count($tools) * 1.5));
    }

    /**
     * Extract and map the finish reason from the Bedrock Converse response.
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        return match ($data['stopReason'] ?? '') {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'tool_use' => FinishReason::ToolCalls,
            'max_tokens' => FinishReason::Length,
            'content_filtered', 'guardrail_intervened' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }

    /**
     * Build the request parameters for the Bedrock Converse API.
     *
     * @param  array<string, mixed>|null  $schemaTools
     * @param  array<string, mixed>|null  $formattedTools  Pre-formatted real tools (used when no schema is active).
     * @param  bool  $toolsEmpty  Whether the caller passed any real tools at all.
     */
    protected function buildConverseParameters(
        string $model,
        ?string $instructions,
        array $conversationMessages,
        ?array $schemaTools,
        ?array $formattedTools,
        bool $toolsEmpty,
        ?TextGenerationOptions $options,
        bool $isFinalStep,
    ): array {
        $parameters = [
            'modelId' => $model,
            'messages' => $conversationMessages,
        ];

        if ($instructions) {
            $parameters['system'] = [['text' => $instructions]];
        }

        $toolConfig = $this->buildToolConfig($schemaTools, $formattedTools, $toolsEmpty, $isFinalStep);

        if ($toolConfig !== null) {
            $parameters['toolConfig'] = $toolConfig;
        }

        $inferenceConfig = $this->buildInferenceConfig($options);

        if (! empty($inferenceConfig)) {
            $parameters['inferenceConfig'] = $inferenceConfig;
        }

        $providerOptions = $options?->providerOptions(Lab::Bedrock);

        if (! empty($providerOptions)) {
            $parameters = array_merge($parameters, $providerOptions);
        }

        return $parameters;
    }

    /**
     * Build the inferenceConfig block for Bedrock's Converse API.
     */
    protected function buildInferenceConfig(?TextGenerationOptions $options): array
    {
        if ($options === null) {
            return [];
        }

        return Arr::whereNotNull([
            'maxTokens' => $options->maxTokens,
            'temperature' => $options->temperature,
            'topP' => $options->topP,
        ]);
    }

    /**
     * Build the assistant conversation message block combining text and tool calls.
     *
     * @param  array<ToolCall>  $toolCalls
     * @param  array<int, array<string, mixed>>  $providerContentBlocks
     */
    protected function buildAssistantConversationMessage(string $text, array $toolCalls, array $providerContentBlocks = []): array
    {
        return $this->formatAssistantMessage(
            new AssistantMessage($text, new Collection($toolCalls), $providerContentBlocks)
        );
    }

    /**
     * Cast empty toolUse.input arrays to objects so the Converse API doesn't reject them.
     *
     * @param  array<int, array<string, mixed>>  $content
     * @return array<int, array<string, mixed>>
     */
    protected function ensureToolInputIsObject(array $content): array
    {
        return array_map(function (array $block) {
            if (isset($block['toolUse'])) {
                $block['toolUse']['input'] = (object) ($block['toolUse']['input'] ?? []);
            }

            return $block;
        }, $content);
    }

    /**
     * Build the user conversation message block carrying tool results.
     *
     * @param  array<ToolResult>  $toolResults
     */
    protected function buildToolResultConversationMessage(array $toolResults): array
    {
        return [
            'role' => 'user',
            'content' => array_map(fn (ToolResult $toolResult) => [
                'toolResult' => [
                    'toolUseId' => $toolResult->id,
                    'content' => [
                        ['text' => is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result)],
                    ],
                ],
            ], $toolResults),
        ];
    }

    /**
     * Build the synthetic structured-output tool plus any real tools.
     */
    protected function buildSchemaTools(array $schema, array $tools): array
    {
        $schemaTools = [
            [
                'toolSpec' => [
                    'name' => self::STRUCTURED_OUTPUT_TOOL,
                    'description' => 'Return the response as a structured JSON object matching the provided schema.',
                    'inputSchema' => [
                        'json' => (new ObjectSchema($schema))->toArray(),
                    ],
                ],
            ],
        ];

        return array_merge($schemaTools, $this->formatTools($tools));
    }

    /**
     * Build Bedrock's toolConfig for the current step.
     *
     * When a schema is present, toolChoice is only forced to the synthetic tool on the
     * final step so real tools can be invoked on earlier iterations.
     */
    protected function buildToolConfig(?array $schemaTools, ?array $formattedTools, bool $toolsEmpty, bool $isFinalStep): ?array
    {
        if ($schemaTools !== null) {
            return [
                'tools' => $schemaTools,
                'toolChoice' => ($isFinalStep || $toolsEmpty)
                    ? ['tool' => ['name' => self::STRUCTURED_OUTPUT_TOOL]]
                    : ['auto' => []],
            ];
        }

        if ($formattedTools !== null) {
            return ['tools' => $formattedTools];
        }

        return null;
    }

    /**
     * Format Laravel AI messages for Bedrock's Converse API.
     */
    protected function formatMessages(array $messages): array
    {
        return (new Collection($messages))->map(fn ($message) => match (true) {
            $message instanceof AssistantMessage => $this->formatAssistantMessage($message),
            $message instanceof ToolResultMessage => $this->formatToolResultMessage($message),
            $message instanceof UserMessage => $this->formatUserMessage($message),
            $message instanceof Message => $this->formatGenericMessage($message),
            default => $this->formatArrayMessage($message),
        })->all();
    }

    /**
     * Format an AssistantMessage for the Converse API.
     */
    protected function formatAssistantMessage(AssistantMessage $message): array
    {
        if (filled($message->providerContentBlocks)) {
            return [
                'role' => 'assistant',
                'content' => $this->ensureToolInputIsObject($message->providerContentBlocks),
            ];
        }

        $content = [];

        if (! empty($message->content)) {
            $content[] = ['text' => $message->content];
        }

        foreach ($message->toolCalls as $toolCall) {
            $content[] = [
                'toolUse' => [
                    'toolUseId' => $toolCall->id,
                    'name' => $toolCall->name,
                    'input' => $toolCall->arguments ?: new stdClass,
                ],
            ];
        }

        return ['role' => 'assistant', 'content' => $content];
    }

    /**
     * Format a ToolResultMessage for the Converse API.
     */
    protected function formatToolResultMessage(ToolResultMessage $message): array
    {
        $content = [];

        foreach ($message->toolResults as $toolResult) {
            $content[] = [
                'toolResult' => [
                    'toolUseId' => $toolResult->id,
                    'content' => [
                        ['text' => is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result)],
                    ],
                ],
            ];
        }

        return ['role' => 'user', 'content' => $content];
    }

    /**
     * Format a UserMessage and its attachments for the Converse API.
     */
    protected function formatUserMessage(UserMessage $message): array
    {
        $content = [['text' => $message->content]];

        if ($message->attachments->isNotEmpty()) {
            $content = array_merge($content, $this->mapAttachments($message->attachments));
        }

        return ['role' => 'user', 'content' => $content];
    }

    /**
     * Format a generic Message (system/user/assistant) for the Converse API.
     */
    protected function formatGenericMessage(Message $message): array
    {
        return [
            'role' => $message->role === MessageRole::Assistant ? 'assistant' : 'user',
            'content' => [['text' => $message->content]],
        ];
    }

    /**
     * Format a raw array-shaped message for the Converse API.
     *
     * @param  array{role: string, content: string}  $message
     */
    protected function formatArrayMessage(array $message): array
    {
        return [
            'role' => $message['role'] === MessageRole::Assistant->value ? 'assistant' : 'user',
            'content' => [['text' => $message['content']]],
        ];
    }

    /**
     * Format tools for the Converse API.
     *
     * @param  array<Tool>  $tools
     */
    protected function formatTools(array $tools): array
    {
        return (new Collection($tools))
            ->filter(fn ($tool) => $tool instanceof Tool)
            ->map(fn (Tool $tool) => [
                'toolSpec' => [
                    'name' => ToolNameResolver::resolve($tool),
                    'description' => (string) $tool->description(),
                    'inputSchema' => [
                        'json' => (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toArray(),
                    ],
                ],
            ])
            ->values()
            ->all();
    }
}
