<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    $this->customUrl = 'http://localhost:1234/v1';
});

test('xai text requests use the configured base url', function (): void {
    config(['ai.providers.xai' => array_filter([
        ...config('ai.providers.xai'),
        'key' => 'test-key',
        'url' => $this->customUrl,
    ])]);

    Http::fake([
        '*' => Http::response(fakeXaiBaseUrlResponseData('Hello from local model')),
    ]);

    $response = agent()->prompt('Hello', provider: 'xai');

    expect($response->text)->toBe('Hello from local model');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === "{$this->customUrl}/responses");
});

test('xai requests fall back to the default base url', function (): void {
    config(['ai.providers.xai' => array_filter([
        ...config('ai.providers.xai'),
        'key' => 'test-key',
    ])]);

    Http::fake([
        '*' => Http::response(fakeXaiBaseUrlResponseData('Hello from xAI')),
    ]);

    $response = agent()->prompt('Hello', provider: 'xai');

    expect($response->text)->toBe('Hello from xAI');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.x.ai/v1/responses');
});

function fakeXaiBaseUrlResponseData(string $text): array
{
    return [
        'id' => 'resp_123',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'grok-4-1-fast-reasoning',
        'output' => [
            [
                'type' => 'message',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'output_text', 'text' => $text],
                ],
            ],
        ],
        'usage' => [
            'input_tokens' => 1,
            'output_tokens' => 1,
        ],
    ];
}
