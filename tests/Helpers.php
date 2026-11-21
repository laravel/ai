<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Providers\TypeSafeProvider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolInputDelta;
use Laravel\Ai\Streaming\Events\ToolInputError;
use Laravel\Ai\Streaming\Events\ToolInputStart;

function requiresApiKey(string ...$keys): void
{
    foreach ($keys as $key) {
        if (empty(env($key))) {
            test()->markTestSkipped("Missing {$key} — skipping external test.");
        }
    }
}

function registerTextOnlyClassifier(): void
{
    config(['ai.providers.text-only' => ['driver' => 'text-only', 'key' => 'test-key']]);

    Ai::extend('text-only', fn ($app, array $config): TypeSafeProvider => new class($config, $app->make(Dispatcher::class)) extends TypeSafeProvider
    {
        public function supportsClassificationAttachments(): bool
        {
            return false;
        }
    });
}

function sentRequest(): Request
{
    [$request] = Http::recorded()->first();

    return $request;
}

function multipartField(Request $request, string $name): ?string
{
    return collect($request->data())->firstWhere('name', $name)['contents'] ?? null;
}

// Laravel 12 flattens nested multipart arrays to "name[]" => value while Laravel 13 keeps "name" => array, so we compare just the values to stay stable across both.
function multipartNestedField(Request $request, string $name): array
{
    return collect($request->data())
        ->reject(fn ($field): bool => isset($field['filename']))
        ->filter(fn ($field): bool => (($field['name'] ?? null) === $name && is_array($field['contents'] ?? null))
            || preg_match('/^'.preg_quote($name, '/').'\[[^\]]*\]$/', $field['name'] ?? '') === 1)
        ->flatMap(fn ($field): array => is_array($field['contents'] ?? null) ? array_values($field['contents']) : [$field['contents'] ?? null])
        ->values()
        ->all();
}

function fakeGroqResponse(string $text = 'Hello'): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-20b',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => $text,
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 1,
            'completion_tokens' => 1,
        ],
    ]);
}

function fakeGroqStreamResponse(string $text = 'Hello'): PromiseInterface
{
    $chunk = fn (array|object $delta, ?string $finishReason = null): array => [
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion.chunk',
        'model' => 'openai/gpt-oss-20b',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
    ];

    $body = implode("\n\n", [
        'data: '.json_encode($chunk(['role' => 'assistant', 'content' => $text])),
        'data: '.json_encode($chunk((object) [], 'stop')),
        'data: [DONE]',
    ])."\n\n";

    return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
}

function fakeGroqStreamErrorResponse(string $message = 'Upstream exploded.'): PromiseInterface
{
    $body = implode("\n\n", [
        'data: '.json_encode(['error' => ['code' => 'server_error', 'message' => $message]]),
        'data: [DONE]',
    ])."\n\n";

    return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
}

function fakeGroqStreamToolCallResponse(string $name = 'FixedNumberGenerator', string $id = 'call_123'): PromiseInterface
{
    $chunk = fn (array|object $delta, ?string $finishReason = null): array => [
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion.chunk',
        'model' => 'openai/gpt-oss-20b',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
    ];

    $body = implode("\n\n", [
        'data: '.json_encode($chunk(['role' => 'assistant', 'tool_calls' => [[
            'index' => 0,
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => ''],
        ]]])),
        'data: '.json_encode($chunk(['tool_calls' => [[
            'index' => 0,
            'function' => ['arguments' => '{}'],
        ]]])),
        'data: '.json_encode($chunk((object) [], 'tool_calls')),
        'data: [DONE]',
    ])."\n\n";

    return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
}

function fakeGroqToolCallResponse(string $name = 'FixedNumberGenerator', array $arguments = [], string $id = 'call_123'): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-tool-123',
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-20b',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => $id,
                    'type' => 'function',
                    'function' => [
                        'name' => $name,
                        'arguments' => $arguments === [] ? '{}' : json_encode($arguments),
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ]);
}

function fakeAnthropicResponse(string $text = 'Hello'): PromiseInterface
{
    return Http::response([
        'id' => 'msg_'.Str::random(8),
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function fakeAnthropicToolUseResponse(string $name, array $input = [], string $id = 'toolu_123'): PromiseInterface
{
    return Http::response([
        'id' => 'msg_'.Str::random(8),
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => (object) $input]],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function fakeAnthropicStreamResponse(string $text = 'Hello'): PromiseInterface
{
    return fakeAnthropicStream(
        ['type' => 'text', 'text' => ''],
        ['type' => 'text_delta', 'text' => $text],
        'end_turn',
    );
}

function fakeAnthropicStreamToolUseResponse(string $name, array $input = [], string $id = 'toolu_123'): PromiseInterface
{
    return fakeAnthropicStream(
        ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => (object) []],
        ['type' => 'input_json_delta', 'partial_json' => json_encode((object) $input)],
        'tool_use',
    );
}

function fakeAnthropicStream(array $block, array $delta, string $stopReason): PromiseInterface
{
    $body = implode("\n\n", array_map(fn (array $event): string => 'data: '.json_encode($event), [
        ['type' => 'message_start', 'message' => ['id' => 'msg_'.Str::random(8), 'model' => 'claude-sonnet-4-6', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => $block],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => $delta],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => $stopReason], 'usage' => ['output_tokens' => 5]],
        ['type' => 'message_stop'],
    ]))."\n\n";

    return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
}

function fakeOllamaResponse(string $text = 'Hello'): PromiseInterface
{
    return Http::response([
        'model' => 'llama3.1:8b',
        'message' => [
            'role' => 'assistant',
            'content' => $text,
        ],
        'done_reason' => 'stop',
        'done' => true,
        'prompt_eval_count' => 1,
        'eval_count' => 1,
    ]);
}

function fakeAzureResponse(string $text = 'Hello'): PromiseInterface
{
    return Http::response([
        'id' => 'resp_azure_123',
        'status' => 'completed',
        'model' => 'gpt-4o',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [[
                'type' => 'output_text',
                'text' => $text,
            ]],
        ]],
        'usage' => [
            'input_tokens' => 1,
            'output_tokens' => 1,
        ],
    ]);
}

function configureOpenAiCompatible(): void
{
    config(['ai.providers.openai-compatible' => [
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'key' => 'test-key',
        'models' => ['text' => ['default' => 'local-model']],
    ]]);
}

function fakeOpenAiResponse(string $text = 'Hello'): PromiseInterface
{
    return Http::response([
        'id' => 'resp_123',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [[
                'type' => 'output_text',
                'text' => $text,
            ]],
        ]],
        'usage' => [
            'input_tokens' => 1,
            'output_tokens' => 1,
        ],
    ]);
}

function openAiReasoningItem(string $id, string ...$summaries): array
{
    return [
        'type' => 'reasoning',
        'id' => $id,
        'summary' => array_map(fn (string $text): array => ['type' => 'summary_text', 'text' => $text], $summaries),
    ];
}

function openAiReasoningTextItem(string $id, string ...$texts): array
{
    return [
        'type' => 'reasoning',
        'id' => $id,
        'summary' => [],
        'content' => array_map(fn (string $text): array => ['type' => 'reasoning_text', 'text' => $text], $texts),
    ];
}

function openAiReasoningItemWithBoth(string $id, string $summary, string $text): array
{
    return [
        'type' => 'reasoning',
        'id' => $id,
        'summary' => [['type' => 'summary_text', 'text' => $summary]],
        'content' => [['type' => 'reasoning_text', 'text' => $text]],
    ];
}

function fakeOpenAiReasonedResponse(array $reasoningItems, string $text = 'Hello'): PromiseInterface
{
    return Http::response([
        'id' => 'resp_123',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [...$reasoningItems, [
            'type' => 'message',
            'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => $text]],
        ]],
        'usage' => [
            'input_tokens' => 1,
            'output_tokens' => 1,
        ],
    ]);
}

function fakeOpenAiReasonedToolCallResponse(array $reasoningItems): PromiseInterface
{
    return Http::response([
        'id' => 'resp_tool_123',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [...$reasoningItems, [
            'type' => 'function_call',
            'id' => 'fc_123',
            'call_id' => 'call_123',
            'name' => 'FixedNumberGenerator',
            'arguments' => '{}',
            'status' => 'completed',
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ]);
}

function fakeOpenAiToolCallResponse(string $id = 'resp_tool_123', string $model = 'gpt-5.4'): PromiseInterface
{
    return Http::response([
        'id' => $id,
        'status' => 'completed',
        'model' => $model,
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_123',
            'call_id' => 'call_123',
            'name' => 'FixedNumberGenerator',
            'arguments' => '{}',
            'status' => 'completed',
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ]);
}

function fakeDeepSeekResponse(string $text = 'Hello'): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-deepseek-123',
        'object' => 'chat.completion',
        'model' => 'deepseek-chat',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => $text,
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 1,
            'completion_tokens' => 1,
        ],
    ]);
}

function fakeOpenRouterResponse(string $text = 'Hello'): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'anthropic/claude-sonnet-4.6',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => $text,
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 1,
            'completion_tokens' => 1,
        ],
    ]);
}

function fakeOpenRouterToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-tool-123',
        'object' => 'chat.completion',
        'model' => 'anthropic/claude-sonnet-4.6',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_123',
                    'type' => 'function',
                    'function' => [
                        'name' => 'FixedNumberGenerator',
                        'arguments' => '{}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ]);
}

function fakeDeepSeekToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-deepseek-tool-123',
        'object' => 'chat.completion',
        'model' => 'deepseek-chat',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_123',
                    'type' => 'function',
                    'function' => [
                        'name' => 'FixedNumberGenerator',
                        'arguments' => '{}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ]);
}

function toolInputEvents(iterable $events): array
{
    return collect($events)
        ->map(fn (StreamEvent $event): ?array => match (true) {
            $event instanceof ToolInputStart => ['start', $event->toolId, $event->toolName],
            $event instanceof ToolInputDelta => ['delta', $event->toolId, $event->delta],
            $event instanceof ToolInputError => ['error', $event->toolId, $event->input],
            $event instanceof ToolCall => ['call', $event->toolCall->id, $event->toolCall->arguments],
            default => null,
        })
        ->filter()
        ->values()
        ->all();
}

/**
 * Prompt the agent, or stream it to the end, and return its response.
 */
function promptOrStream(Agent $agent, string $mode, mixed $input): AgentResponse|StreamableAgentResponse
{
    if ($mode === 'prompt') {
        return $agent->prompt($input);
    }

    $response = $agent->stream($input);

    foreach ($response as $event) {
        //
    }

    return $response;
}

/**
 * Get the tool calls stored with a conversation's assistant turns, in order.
 */
function storedToolCalls(string $conversationId): array
{
    return DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->orderBy('id')
        ->pluck('steps')
        ->flatMap(fn (string $steps): array => array_merge(...array_column(json_decode($steps, true), 'tool_calls')))
        ->values()
        ->all();
}

/**
 * Get the content of a conversation's stored messages, in order.
 */
function storedMessageContents(string $conversationId): array
{
    return DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->orderBy('id')
        ->pluck('content')
        ->all();
}

/**
 * Get the ID of the conversation a user message with the given content was stored in.
 */
function conversationContaining(string $message): ?string
{
    return DB::table('agent_conversation_messages')
        ->where('role', 'user')
        ->where('content', $message)
        ->value('conversation_id');
}
