<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Laravel\Ai\agent;

beforeEach(function () {
    $this->customUrl = 'http://localhost:1234/v1';
});

test('vercel text requests use the configured base url', function () {
    configureVercelProvider($this->customUrl);

    Http::fake(['*' => fakeOpenRouterResponse('Hello from local model')]);

    $response = agent()->prompt('Hello', provider: 'vercel');

    expect($response->text)->toBe('Hello from local model');

    Http::assertSentCount(1);
    vercelAssertRequestSent('POST', "{$this->customUrl}/chat/completions");
});

test('vercel requests fall back to the default base url', function () {
    configureVercelProvider();

    Http::fake(['*' => fakeOpenRouterResponse('Hello from Vercel')]);

    $response = agent()->prompt('Hello', provider: 'vercel');

    expect($response->text)->toBe('Hello from Vercel');

    Http::assertSentCount(1);
    vercelAssertRequestSent('POST', 'https://ai-gateway.vercel.sh/v1/chat/completions');
});

test('vercel provider does not support audio generation', function () {
    configureVercelProvider();

    expect(fn () => \Laravel\Ai\Ai::audioProvider('vercel'))
        ->toThrow(LogicException::class);
});

function configureVercelProvider(?string $url = null): void
{
    config(['ai.providers.vercel' => array_filter([
        ...config('ai.providers.vercel'),
        'key' => 'test-key',
        'url' => $url,
    ])]);
}

function vercelAssertRequestSent(string $method, string $url): void
{
    Http::assertSent(fn (Request $request) => $request->method() === $method
        && $request->url() === $url);
}
