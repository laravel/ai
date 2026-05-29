<?php

namespace Laravel\Ai\Gateway\Oracle;

use Generator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\Oracle\Concerns\CreatesOracleClient;
use Laravel\Ai\Gateway\Oracle\Concerns\DetectsModelFamily;
use Laravel\Ai\Gateway\Oracle\Concerns\MapsCohereChat;
use Laravel\Ai\Gateway\Oracle\Concerns\MapsGenericChat;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Throwable;

class OracleTextGateway implements EmbeddingGateway, TextGateway
{
    use CreatesOracleClient;
    use DetectsModelFamily;
    use HandlesFailoverErrors;
    use InvokesTools;
    use MapsCohereChat;
    use MapsGenericChat;
    use ParsesServerSentEvents;

    protected const STRUCTURED_OUTPUT_TOOL = 'structured_output';

    /**
     * The OCI Generative AI API version path segment.
     */
    protected const API_VERSION = '20231130';

    /**
     * The number of inputs sent per embedText call.
     *
     * The API constraint is per-input token length rather than a fixed array bound, but the
     * OCI console caps batches at 96 inputs; we mirror that as a safe client-side batch size.
     */
    protected const EMBED_BATCH_SIZE = 96;

    /**
     * The structural chat-request keys that agent provider options may not override.
     *
     * @var list<string>
     */
    protected const RESERVED_REQUEST_KEYS = [
        'apiFormat', 'messages', 'message', 'chatHistory', 'preambleOverride',
        'tools', 'toolChoice', 'toolResults', 'isForceSingleStep', 'isStream', 'streamOptions',
    ];

    public function __construct()
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
        return $this->isCohereModel($model)
            ? $this->generateCohereText($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout)
            : $this->generateGenericText($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout);
    }

    /**
     * Generate text using the GENERIC chat format (Llama, Grok, etc.).
     */
    protected function generateGenericText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
    ): TextResponse {
        $conversation = $this->buildGenericMessages($messages, $instructions);
        $maxSteps = $this->resolveMaxSteps($tools, $options);
        $schemaTools = $schema ? $this->buildGenericSchemaTools($schema, $tools) : null;
        $formattedTools = $schemaTools === null && ! empty($tools) ? $this->formatGenericTools($tools) : null;

        $allToolCalls = [];
        $allToolResults = [];
        $finalOutput = '';
        $totalUsage = new Usage;
        $step = 0;
        $responseMessages = new Collection;
        $steps = new Collection;
        $meta = new Meta($provider->name(), $model);

        while ($step < $maxSteps) {
            $chatRequest = $this->buildGenericChatRequest(
                $conversation, $schemaTools, $formattedTools, empty($tools), $options, ($step + 1) >= $maxSteps,
            );

            $parsed = $this->parseGenericResponse($this->postChat($provider, $model, $chatRequest, $timeout));

            $totalUsage = $totalUsage->add($parsed['usage']);

            [$toolCalls, $structured] = $this->extractStructuredToolCall($parsed['toolCalls'], $schemaTools !== null);

            if ($structured !== null) {
                $finalOutput = $structured;
            } elseif (! $schemaTools) {
                $finalOutput = $parsed['text'];
            }

            $step++;
            $finishReason = $parsed['finishReason'];

            $responseMessages->push(new AssistantMessage($parsed['text'], new Collection($toolCalls)));

            if (empty($toolCalls)) {
                if ($schemaTools && $finishReason === FinishReason::ToolCalls) {
                    $finishReason = FinishReason::Stop;
                }

                $steps->push(new Step($parsed['text'], $toolCalls, [], $finishReason, $parsed['usage'], $meta));

                break;
            }

            $allToolCalls = array_merge($allToolCalls, $toolCalls);
            $conversation[] = $this->genericAssistantMessage($parsed['text'], $toolCalls);

            $toolResults = $this->executeToolCalls($tools, $toolCalls);
            $allToolResults = array_merge($allToolResults, $toolResults);

            $steps->push(new Step($parsed['text'], $toolCalls, $toolResults, $finishReason, $parsed['usage'], $meta));

            if (! empty($toolResults)) {
                $conversation = array_merge($conversation, $this->genericToolResultMessages($toolResults));
                $responseMessages->push(new ToolResultMessage(new Collection($toolResults)));
            }
        }

        return $this->buildTextResponse($schema, $finalOutput, $totalUsage, $meta, $responseMessages, $steps, $allToolCalls, $allToolResults);
    }

    /**
     * Generate text using the COHERE chat format (cohere.command-* models).
     */
    protected function generateCohereText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
    ): TextResponse {
        $state = $this->buildCohereState($messages, $instructions);
        $maxSteps = $this->resolveMaxSteps($tools, $options);
        $schemaTool = $schema ? $this->buildCohereSchemaTool($schema) : null;
        $formattedTools = $schemaTool === null && ! empty($tools) ? $this->formatCohereTools($tools) : null;

        $allToolCalls = [];
        $allToolResults = [];
        $finalOutput = '';
        $totalUsage = new Usage;
        $step = 0;
        $pendingToolResults = [];
        $responseMessages = new Collection;
        $steps = new Collection;
        $meta = new Meta($provider->name(), $model);

        while ($step < $maxSteps) {
            $chatRequest = $this->buildCohereChatRequest(
                $state, $formattedTools, $schemaTool, $options, $pendingToolResults,
            );

            $parsed = $this->parseCohereResponse($this->postChat($provider, $model, $chatRequest, $timeout));

            $totalUsage = $totalUsage->add($parsed['usage']);

            [$toolCalls, $structured] = $this->extractStructuredToolCall($parsed['toolCalls'], $schemaTool !== null);

            if ($structured !== null) {
                $finalOutput = $structured;
            } elseif (! $schemaTool) {
                $finalOutput = $parsed['text'];
            }

            $step++;
            $finishReason = $parsed['finishReason'];

            $responseMessages->push(new AssistantMessage($parsed['text'], new Collection($toolCalls)));

            if (empty($toolCalls)) {
                $steps->push(new Step($parsed['text'], $toolCalls, [], $finishReason, $parsed['usage'], $meta));

                break;
            }

            $allToolCalls = array_merge($allToolCalls, $toolCalls);

            if ($parsed['text'] !== '') {
                $state['chatHistory'][] = ['role' => 'CHATBOT', 'message' => $parsed['text']];
            }

            $toolResults = $this->executeToolCalls($tools, $toolCalls);
            $allToolResults = array_merge($allToolResults, $toolResults);
            $pendingToolResults = array_merge($pendingToolResults, $this->buildCohereToolResults($toolResults));

            $steps->push(new Step($parsed['text'], $toolCalls, $toolResults, $finishReason, $parsed['usage'], $meta));

            if (! empty($toolResults)) {
                $responseMessages->push(new ToolResultMessage(new Collection($toolResults)));
            }
        }

        return $this->buildTextResponse($schema, $finalOutput, $totalUsage, $meta, $responseMessages, $steps, $allToolCalls, $allToolResults);
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
        $cohere = $this->isCohereModel($model);

        $chatRequest = $cohere
            ? $this->buildCohereChatRequest(
                $this->buildCohereState($messages, $instructions),
                empty($tools) ? null : $this->formatCohereTools($tools),
                null, $options, [], isStream: true,
            )
            : $this->buildGenericChatRequest(
                $this->buildGenericMessages($messages, $instructions),
                null, empty($tools) ? null : $this->formatGenericTools($tools),
                empty($tools), $options, true, isStream: true,
            );

        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $this->client($provider, $timeout)
                    ->withOptions(['stream' => true])
                    ->post(self::API_VERSION.'/actions/chat', $this->buildChatDetails($provider, $model, $chatRequest)),
            );
        } catch (Throwable $e) {
            throw OracleException::toAiException($e, $provider->name(), $model);
        }

        $messageId = (string) Str::uuid();
        $timestamp = time();

        yield (new StreamStart((string) Str::uuid(), $provider->name(), $model, $timestamp))
            ->withInvocationId($invocationId);

        $textStarted = false;
        $pendingToolCalls = [];
        $usage = new Usage;
        $finishReason = 'stop';

        foreach ($this->parseServerSentEvents($response->getBody()) as $event) {
            if ($delta = $this->streamTextDelta($event)) {
                if (! $textStarted) {
                    $textStarted = true;

                    yield (new TextStart((string) Str::uuid(), $messageId, $timestamp))->withInvocationId($invocationId);
                }

                yield (new TextDelta((string) Str::uuid(), $messageId, $delta, $timestamp))->withInvocationId($invocationId);
            }

            $this->accumulateStreamToolCalls($event, $pendingToolCalls);

            if ($extracted = $this->streamUsage($event)) {
                $usage = $extracted;
            }

            if ($reason = $this->streamFinishReason($event)) {
                $finishReason = $reason;
            }
        }

        if ($textStarted) {
            yield (new TextEnd((string) Str::uuid(), $messageId, $timestamp))->withInvocationId($invocationId);
        }

        $toolCalls = $this->finalizeStreamToolCalls($pendingToolCalls);

        if (! empty($toolCalls)) {
            foreach ($toolCalls as $toolCall) {
                yield (new ToolCallEvent((string) Str::uuid(), $toolCall, $timestamp))->withInvocationId($invocationId);
            }

            foreach ($this->executeToolCalls($tools, $toolCalls) as $toolResult) {
                yield (new ToolResultEvent((string) Str::uuid(), $toolResult, true, null, $timestamp))->withInvocationId($invocationId);
            }
        }

        yield (new StreamEnd($messageId, $finishReason, $usage, $timestamp))->withInvocationId($invocationId);
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
        $embeddings = [];
        $totalTokens = 0;

        // Cohere embed v3 models have a fixed output dimension, so the requested dimension is
        // only forwarded for model families that accept the configurable outputDimensions field.
        $dimensionPayload = $this->supportsOutputDimensions($model)
            ? ['outputDimensions' => $dimensions]
            : [];

        foreach (array_chunk(array_values($inputs), self::EMBED_BATCH_SIZE) as $batch) {
            $payload = array_merge([
                'inputType' => 'SEARCH_DOCUMENT',
                'truncate' => 'END',
            ], $dimensionPayload, $providerOptions, [
                ...$this->servingPayload($provider, $model),
                'inputs' => $batch,
            ]);

            try {
                $response = $this->withErrorHandling(
                    $provider->name(),
                    fn () => $this->client($provider, $timeout)->post(self::API_VERSION.'/actions/embedText', $payload),
                );

                $data = $response->json();
            } catch (Throwable $e) {
                throw OracleException::toAiException($e, $provider->name(), $model);
            }

            $embeddings = array_merge($embeddings, $data['embeddings'] ?? []);
            $totalTokens += $data['usage']['totalTokens'] ?? 0;
        }

        return new EmbeddingsResponse($embeddings, $totalTokens, new Meta($provider->name(), $model));
    }

    /**
     * Build the GenericChatRequest body.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>|null  $schemaTools
     * @param  array<int, array<string, mixed>>|null  $formattedTools
     * @return array<string, mixed>
     */
    protected function buildGenericChatRequest(
        array $messages,
        ?array $schemaTools,
        ?array $formattedTools,
        bool $toolsEmpty,
        ?TextGenerationOptions $options,
        bool $isFinalStep,
        bool $isStream = false,
    ): array {
        $request = array_merge([
            'apiFormat' => 'GENERIC',
            'messages' => $messages,
        ], $this->buildInferenceFields($options));

        if ($schemaTools !== null) {
            $request['tools'] = $schemaTools;
            $request['toolChoice'] = ($isFinalStep || $toolsEmpty)
                ? ['type' => 'FUNCTION', 'name' => self::STRUCTURED_OUTPUT_TOOL]
                : ['type' => 'AUTO'];
        } elseif ($formattedTools !== null) {
            $request['tools'] = $formattedTools;
        }

        if ($isStream) {
            $request['isStream'] = true;
            $request['streamOptions'] = ['isIncludeUsage' => true];
        }

        return $this->mergeProviderOptions($request, $options);
    }

    /**
     * Build the CohereChatRequest body.
     *
     * @param  array{message: string, chatHistory: array<int, array<string, string>>, preamble: ?string}  $state
     * @param  array<int, array<string, mixed>>|null  $formattedTools
     * @param  array<int, array<string, mixed>>|null  $schemaTool
     * @param  array<int, array<string, mixed>>  $toolResults
     * @return array<string, mixed>
     */
    protected function buildCohereChatRequest(
        array $state,
        ?array $formattedTools,
        ?array $schemaTool,
        ?TextGenerationOptions $options,
        array $toolResults = [],
        bool $isStream = false,
    ): array {
        $request = array_merge([
            'apiFormat' => 'COHERE',
            'message' => $state['message'],
        ], $this->buildInferenceFields($options));

        if ($state['preamble'] !== null) {
            $request['preambleOverride'] = $state['preamble'];
        }

        if (! empty($state['chatHistory'])) {
            $request['chatHistory'] = $state['chatHistory'];
        }

        if ($schemaTool !== null) {
            $request['tools'] = $schemaTool;
            $request['isForceSingleStep'] = true;
        } elseif ($formattedTools !== null) {
            $request['tools'] = $formattedTools;
        }

        if (! empty($toolResults)) {
            $request['toolResults'] = $toolResults;
        }

        if ($isStream) {
            $request['isStream'] = true;
            $request['streamOptions'] = ['isIncludeUsage' => true];
        }

        return $this->mergeProviderOptions($request, $options);
    }

    /**
     * Wrap a chatRequest in the OCI ChatDetails envelope (compartment + serving mode).
     *
     * @param  array<string, mixed>  $chatRequest
     * @return array<string, mixed>
     */
    protected function buildChatDetails(TextProvider $provider, string $model, array $chatRequest): array
    {
        return array_filter([
            ...$this->servingPayload($provider, $model),
            'chatRequest' => $chatRequest,
        ], fn ($value) => $value !== null);
    }

    /**
     * Build the shared compartmentId + servingMode payload for chat and embedding requests.
     *
     * @return array<string, mixed>
     */
    protected function servingPayload(TextProvider|EmbeddingProvider $provider, string $model): array
    {
        $config = $provider->additionalConfiguration();

        $servingMode = ($config['serving_type'] ?? 'ON_DEMAND') === 'DEDICATED' && ! empty($config['endpoint_id'])
            ? ['servingType' => 'DEDICATED', 'endpointId' => $config['endpoint_id']]
            : ['servingType' => 'ON_DEMAND', 'modelId' => $model];

        return [
            'compartmentId' => $config['compartment_id'] ?? null,
            'servingMode' => $servingMode,
        ];
    }

    /**
     * Build the shared inference fields (maxTokens / temperature / topP).
     *
     * @return array<string, mixed>
     */
    protected function buildInferenceFields(?TextGenerationOptions $options): array
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
     * Merge the agent's Oracle provider options into the chat request.
     *
     * Reserved structural keys are stripped first so provider options can only tune inference
     * parameters (penalties, seed, etc.) and never break request invariants.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function mergeProviderOptions(array $request, ?TextGenerationOptions $options): array
    {
        $providerOptions = $options?->providerOptions(Lab::Oracle);

        if (empty($providerOptions)) {
            return $request;
        }

        $providerOptions = array_diff_key($providerOptions, array_flip(self::RESERVED_REQUEST_KEYS));

        return array_merge($request, $providerOptions);
    }

    /**
     * Send a chat request and return the inner chatResponse payload.
     *
     * @param  array<string, mixed>  $chatRequest
     * @return array<string, mixed>
     */
    protected function postChat(TextProvider $provider, string $model, array $chatRequest, ?int $timeout): array
    {
        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $this->client($provider, $timeout)->post(
                    self::API_VERSION.'/actions/chat',
                    $this->buildChatDetails($provider, $model, $chatRequest),
                ),
            );

            return $response->json('chatResponse', []);
        } catch (Throwable $e) {
            throw OracleException::toAiException($e, $provider->name(), $model);
        }
    }

    /**
     * Split out the synthetic structured-output tool call from the real tool calls.
     *
     * @param  array<ToolCall>  $toolCalls
     * @return array{0: array<ToolCall>, 1: ?string}
     */
    protected function extractStructuredToolCall(array $toolCalls, bool $hasSchema): array
    {
        if (! $hasSchema) {
            return [$toolCalls, null];
        }

        $structured = null;
        $remaining = [];

        foreach ($toolCalls as $toolCall) {
            if ($toolCall->name === self::STRUCTURED_OUTPUT_TOOL) {
                $structured = json_encode($toolCall->arguments ?: []);

                continue;
            }

            $remaining[] = $toolCall;
        }

        return [$remaining, $structured];
    }

    /**
     * Build the final text or structured response.
     *
     * @param  array<string, mixed>|null  $schema
     * @param  array<ToolCall>  $allToolCalls
     * @param  array<ToolResult>  $allToolResults
     */
    protected function buildTextResponse(
        ?array $schema,
        string $finalOutput,
        Usage $totalUsage,
        Meta $meta,
        Collection $responseMessages,
        Collection $steps,
        array $allToolCalls,
        array $allToolResults,
    ): TextResponse {
        if ($schema) {
            $structured = json_decode($finalOutput, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $structured = [];
            }

            return (new StructuredTextResponse($structured, $finalOutput, $totalUsage, $meta))
                ->withToolCallsAndResults(new Collection($allToolCalls), new Collection($allToolResults))
                ->withSteps($steps);
        }

        return (new TextResponse($finalOutput, $totalUsage, $meta))
            ->withMessages($responseMessages)
            ->withSteps($steps);
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
     * Extract an incremental text delta from a streaming event (defensive across both families).
     *
     * @param  array<string, mixed>  $event
     */
    protected function streamTextDelta(array $event): string
    {
        if (isset($event['text']) && is_string($event['text'])) {
            return $event['text'];
        }

        $delta = '';

        foreach ($event['message']['content'] ?? [] as $part) {
            if (isset($part['text'])) {
                $delta .= $part['text'];
            }
        }

        return $delta;
    }

    /**
     * Accumulate streamed tool-call fragments by index across both families.
     *
     * @param  array<string, mixed>  $event
     * @param  array<int, array{id: string, name: string, arguments: string}>  $pending
     */
    protected function accumulateStreamToolCalls(array $event, array &$pending): void
    {
        $calls = $event['toolCalls'] ?? $event['message']['toolCalls'] ?? [];

        foreach ($calls as $index => $call) {
            $index = $call['index'] ?? $index;

            $pending[$index] ??= ['id' => '', 'name' => '', 'arguments' => ''];

            if (! empty($call['id'])) {
                $pending[$index]['id'] = $call['id'];
            }

            if (! empty($call['name'])) {
                $pending[$index]['name'] = $call['name'];
            }

            $arguments = $call['arguments'] ?? $call['parameters'] ?? null;

            if (is_array($arguments) || is_object($arguments)) {
                $pending[$index]['arguments'] = (string) json_encode($arguments);
            } elseif (is_string($arguments)) {
                $pending[$index]['arguments'] .= $arguments;
            }
        }
    }

    /**
     * Convert accumulated streaming tool-call fragments into ToolCall objects.
     *
     * @param  array<int, array{id: string, name: string, arguments: string}>  $pending
     * @return array<ToolCall>
     */
    protected function finalizeStreamToolCalls(array $pending): array
    {
        $toolCalls = [];

        foreach ($pending as $call) {
            if ($call['name'] === '') {
                continue;
            }

            $toolCalls[] = new ToolCall(
                $call['id'] !== '' ? $call['id'] : (string) Str::uuid(),
                $call['name'],
                $this->decodeArguments($call['arguments']),
            );
        }

        return $toolCalls;
    }

    /**
     * Extract token usage from a streaming event, if present.
     *
     * @param  array<string, mixed>  $event
     */
    protected function streamUsage(array $event): ?Usage
    {
        if (! isset($event['usage'])) {
            return null;
        }

        return new Usage(
            promptTokens: $event['usage']['promptTokens'] ?? 0,
            completionTokens: $event['usage']['completionTokens'] ?? 0,
        );
    }

    /**
     * Extract the mapped finish reason from a streaming event, if present.
     *
     * @param  array<string, mixed>  $event
     */
    protected function streamFinishReason(array $event): ?string
    {
        if (empty($event['finishReason'])) {
            return null;
        }

        $reason = $this->isCohereFinishReason($event['finishReason'])
            ? $this->cohereFinishReason($event['finishReason'])
            : $this->genericFinishReason($event['finishReason']);

        return $reason->value;
    }

    /**
     * Determine whether a streamed finish reason uses the Cohere (uppercase) vocabulary.
     */
    protected function isCohereFinishReason(string $reason): bool
    {
        return in_array(strtoupper($reason), ['COMPLETE', 'MAX_TOKENS', 'ERROR_TOXIC', 'ERROR_LIMIT', 'USER_CANCEL'], true);
    }

    /**
     * Execute the tool calls against the provided tools and collect results.
     *
     * @param  array<Tool>  $tools
     * @param  array<ToolCall>  $toolCalls
     * @return array<ToolResult>
     */
    protected function executeToolCalls(array $tools, array $toolCalls): array
    {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $tool = $this->findTool($toolCall->name, $tools);

            if ($tool === null) {
                $results[] = new ToolResult(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    'Error: Tool "'.$toolCall->name.'" not found.',
                );

                continue;
            }

            $results[] = new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                $this->executeTool($tool, $toolCall->arguments),
            );
        }

        return $results;
    }
}
