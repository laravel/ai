<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Laravel\Ai\agent;

beforeEach(function () {
    $this->customUrl = 'http://localhost:1234/v1';
});

test('qianfan text requests use the configured base url', function () {
    configureQianfanProvider($this->customUrl);

    Http::fake(['*' => $this->fakeQianfanResponse('Hello from local model')]);

    $response = agent()->prompt('Hello', provider: 'qianfan');

    expect($response->text)->toBe('Hello from local model');

    Http::assertSentCount(1);
    qianfanAssertRequestSent('POST', "{$this->customUrl}/chat/completions");
});

test('qianfan requests fall back to the default base url', function () {
    configureQianfanProvider();

    Http::fake(['*' => $this->fakeQianfanResponse('Hello from Qianfan')]);

    $response = agent()->prompt('Hello', provider: 'qianfan');

    expect($response->text)->toBe('Hello from Qianfan');

    Http::assertSentCount(1);
    qianfanAssertRequestSent('POST', 'https://api.baiduqianfan.ai/v1/chat/completions');
});

test('qianfan trims trailing slash from configured base url', function () {
    configureQianfanProvider('https://example.test/v1/');

    Http::fake(['*' => $this->fakeQianfanResponse('Hello')]);

    agent()->prompt('Hello', provider: 'qianfan');

    Http::assertSentCount(1);
    qianfanAssertRequestSent('POST', 'https://example.test/v1/chat/completions');
});

function configureQianfanProvider(?string $url = null): void
{
    config(['ai.providers.qianfan' => array_filter([
        ...config('ai.providers.qianfan'),
        'key' => 'test-key',
        'url' => $url,
    ])]);
}

function qianfanAssertRequestSent(string $method, string $url): void
{
    Http::assertSent(fn (Request $request) => $request->method() === $method
        && $request->url() === $url);
}
