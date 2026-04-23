<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.litellm' => [
        ...config('ai.providers.litellm'),
        'key' => 'test-key',
    ]]);
});

test('litellm text requests use the configured base url', function () {
    $customUrl = 'http://litellm-proxy:8080';

    config(['ai.providers.litellm' => [
        ...config('ai.providers.litellm'),
        'url' => $customUrl,
    ]]);

    Http::fake([
        '*' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'openai/gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Hello from LiteLLM',
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 1,
                'completion_tokens' => 1,
            ],
        ]),
    ]);

    $response = agent()->prompt('Hello', provider: 'litellm');

    expect($response->text)->toBe('Hello from LiteLLM');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === "{$customUrl}/chat/completions");
});

test('litellm requests fall back to the default base url', function () {
    Http::fake([
        '*' => Http::response([
            'id' => 'chatcmpl-456',
            'object' => 'chat.completion',
            'model' => 'openai/gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Hello from default',
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 1,
                'completion_tokens' => 1,
            ],
        ]),
    ]);

    $response = agent()->prompt('Hello', provider: 'litellm');

    expect($response->text)->toBe('Hello from default');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'http://localhost:4000/chat/completions');
});
