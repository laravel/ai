<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Laravel\Ai\agent;

beforeEach(function () {
    $this->customUrl = 'http://localhost:8000/v1';
});

test('nvidia text requests use the configured base url', function () {
    configureNvidiaProvider($this->customUrl);

    Http::fake([
        '*' => fakeNvidiaResponse('Hello from local NIM'),
    ]);

    $response = agent()->prompt('Hello', provider: 'nvidia');

    expect($response->text)->toBe('Hello from local NIM');

    Http::assertSentCount(1);
    nvidiaAssertRequestSent('POST', "{$this->customUrl}/chat/completions");
});

test('nvidia requests fall back to the default base url', function () {
    configureNvidiaProvider();

    Http::fake([
        '*' => fakeNvidiaResponse('Hello from NIM'),
    ]);

    $response = agent()->prompt('Hello', provider: 'nvidia');

    expect($response->text)->toBe('Hello from NIM');

    Http::assertSentCount(1);
    nvidiaAssertRequestSent('POST', 'https://integrate.api.nvidia.com/v1/chat/completions');
});

function configureNvidiaProvider(?string $url = null): void
{
    config(['ai.providers.nvidia' => array_filter([
        ...config('ai.providers.nvidia'),
        'key' => 'test-key',
        'url' => $url,
    ])]);
}

function nvidiaAssertRequestSent(string $method, string $url): void
{
    Http::assertSent(fn (Request $request) => $request->method() === $method
        && $request->url() === $url);
}
