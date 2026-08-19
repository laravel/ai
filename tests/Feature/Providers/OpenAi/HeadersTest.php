<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\HeadersAgent;

beforeEach(function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('custom headers are included in openai request', function (): void {
    Http::fake([
        '*' => fakeOpenAiResponse('Hello'),
    ]);

    (new HeadersAgent)->prompt('Hello', provider: 'openai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-Custom-Header', 'openai-value')
            && $request->hasHeader('X-Request-Source', 'laravel-ai')
            && ! array_key_exists('X-Custom-Header', $body)
            && ! array_key_exists('X-Request-Source', $body);
    });
});

test('request does not contain custom headers when agent does not implement interface', function (): void {
    Http::fake([
        '*' => fakeOpenAiResponse('Hello'),
    ]);

    (new AssistantAgent)->prompt('Hello', provider: 'openai');

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-Custom-Header')
        && ! $request->hasHeader('X-Request-Source'));
});
