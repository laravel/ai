<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

function fakePngFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'laravel-ai-').'.png';

    file_put_contents($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    ));

    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

function requiresApiKey(string ...$keys): void
{
    foreach ($keys as $key) {
        if (empty(env($key))) {
            test()->markTestSkipped("Missing {$key} — skipping external test.");
        }
    }
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
