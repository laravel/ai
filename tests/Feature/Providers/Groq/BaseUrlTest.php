<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    $this->customUrl = 'http://localhost:1234/v1';
});

test('groq text requests use the configured base url', function (): void {
    configureGroqProvider($this->customUrl);

    Http::fake([
        '*' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'openai/gpt-oss-20b',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Hello from local model',
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 1,
                'completion_tokens' => 1,
            ],
        ]),
    ]);

    $response = agent()->prompt('Hello', provider: 'groq');

    expect($response->text)->toBe('Hello from local model');

    Http::assertSentCount(1);
    groqAssertRequestSent('POST', "{$this->customUrl}/chat/completions");
});

test('groq requests fall back to the default base url', function (): void {
    configureGroqProvider();

    Http::fake([
        '*' => Http::response([
            'id' => 'chatcmpl-456',
            'object' => 'chat.completion',
            'model' => 'openai/gpt-oss-20b',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Hello from Groq',
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 1,
                'completion_tokens' => 1,
            ],
        ]),
    ]);

    $response = agent()->prompt('Hello', provider: 'groq');

    expect($response->text)->toBe('Hello from Groq');

    Http::assertSentCount(1);
    groqAssertRequestSent('POST', 'https://api.groq.com/openai/v1/chat/completions');
});

function configureGroqProvider(?string $url = null): void
{
    config(['ai.providers.groq' => array_filter([
        ...config('ai.providers.groq'),
        'key' => 'test-key',
        'url' => $url,
    ])]);
}

function groqAssertRequestSent(string $method, string $url): void
{
    Http::assertSent(fn (Request $request): bool => $request->method() === $method
        && $request->url() === $url);
}
