<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Laravel\Ai\agent;

test('openrouter text requests use the configured base url', function () {
    $customUrl = 'http://localhost:1234/v1';

    configureOpenRouterProvider($customUrl);

    Http::fake(['*' => fakeOpenRouterResponse('Hello from local model')]);

    $response = agent()->prompt('Hello', provider: 'openrouter');

    expect($response->text)->toBe('Hello from local model');

    Http::assertSentCount(1);
    openRouterAssertRequestSent('POST', "{$customUrl}/chat/completions");
});

test('openrouter requests fall back to the default base url', function () {
    configureOpenRouterProvider();

    Http::fake(['*' => fakeOpenRouterResponse('Hello from OpenRouter')]);

    $response = agent()->prompt('Hello', provider: 'openrouter');

    expect($response->text)->toBe('Hello from OpenRouter');

    Http::assertSentCount(1);
    openRouterAssertRequestSent('POST', 'https://openrouter.ai/api/v1/chat/completions');
});

function configureOpenRouterProvider(?string $url = null): void
{
    config(['ai.providers.openrouter' => array_filter([
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
        'url' => $url,
    ])]);
}

function openRouterAssertRequestSent(string $method, string $url): void
{
    Http::assertSent(fn (Request $request) => $request->method() === $method
        && $request->url() === $url);
}
