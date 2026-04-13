<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Laravel\Ai\agent;

beforeEach(function () {
    $this->customUrl = 'http://localhost:1234/v1';
});

test('deepseek text requests use the configured base url', function () {
    configureDeepSeekProvider($this->customUrl);

    Http::fake([
        '*' => fakeDeepSeekResponse('Hello from local model'),
    ]);

    $response = agent()->prompt('Hello', provider: 'deepseek');

    expect($response->text)->toBe('Hello from local model');

    Http::assertSentCount(1);
    deepseekAssertRequestSent('POST', "{$this->customUrl}/chat/completions");
});

test('deepseek requests fall back to the default base url', function () {
    configureDeepSeekProvider();

    Http::fake([
        '*' => fakeDeepSeekResponse('Hello from DeepSeek'),
    ]);

    $response = agent()->prompt('Hello', provider: 'deepseek');

    expect($response->text)->toBe('Hello from DeepSeek');

    Http::assertSentCount(1);
    deepseekAssertRequestSent('POST', 'https://api.deepseek.com/v1/chat/completions');
});

function configureDeepSeekProvider(?string $url = null): void
{
    config(['ai.providers.deepseek' => array_filter([
        ...config('ai.providers.deepseek'),
        'key' => 'test-key',
        'url' => $url,
    ])]);
}

function deepseekAssertRequestSent(string $method, string $url): void
{
    Http::assertSent(fn (Request $request) => $request->method() === $method
        && $request->url() === $url);
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
