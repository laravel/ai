<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\HeadersAgent;

beforeEach(function (): void {
    config([
        'ai.providers.openai' => [...config('ai.providers.openai'), 'key' => 'test-key'],
        'ai.providers.groq' => [...config('ai.providers.groq'), 'key' => 'test-key'],
    ]);
});

test('agent headers are sent with the request and stay out of the body', function (): void {
    Http::fake(['api.openai.com/*' => fakeOpenAiResponse('Hello')]);

    (new HeadersAgent)->prompt('Hi', provider: 'openai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-Custom-Header', 'openai-value')
            && $request->hasHeader('X-Request-Source', 'laravel-ai')
            && ! array_key_exists('ai_sdk_extra_headers', $body)
            && ! array_key_exists('X-Custom-Header', $body);
    });
});

test('agent headers are resolved for the provider the request is sent to', function (): void {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse('Hello'),
        'api.groq.com/*' => fakeGroqResponse('Hello'),
    ]);

    (new HeadersAgent)->prompt('Hi', provider: 'openai');
    (new HeadersAgent)->prompt('Hi', provider: 'groq');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'openai')
        && $request->hasHeader('X-Custom-Header', 'openai-value'));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'groq')
        && $request->hasHeader('X-Custom-Header', 'groq-value'));
});

test('agent headers are sent while streaming', function (): void {
    Http::fake(['api.groq.com/*' => fakeGroqStreamResponse('Hello')]);

    foreach ((new HeadersAgent)->stream('Hi', provider: 'groq') as $event) {
        //
    }

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Custom-Header', 'groq-value'));
});

test('no headers are sent when the agent does not implement the contract', function (): void {
    Http::fake(['api.openai.com/*' => fakeOpenAiResponse('Hello')]);

    (new AssistantAgent)->prompt('Hi', provider: 'openai');

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-Custom-Header'));
});
