<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

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
