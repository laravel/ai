<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function requiresApiKey(string ...$keys): void
{
    foreach ($keys as $key) {
        if (empty(env($key))) {
            test()->markTestSkipped("Missing {$key} — skipping external test.");
        }
    }
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
