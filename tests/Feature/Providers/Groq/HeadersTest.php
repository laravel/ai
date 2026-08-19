<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\HeadersAgent;

beforeEach(function (): void {
    config(['ai.providers.groq' => [
        ...config('ai.providers.groq'),
        'key' => 'test-key',
    ]]);
});

test('custom headers are included in groq request', function (): void {
    Http::fake(['*' => fakeGroqResponse('Hello')]);

    (new HeadersAgent)->prompt('Hello', provider: 'groq');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-Custom-Header', 'groq-value')
            && ! array_key_exists('X-Custom-Header', $body);
    });
});

test('request does not contain custom headers when agent does not implement interface', function (): void {
    Http::fake(['*' => fakeGroqResponse('Hello')]);

    (new AssistantAgent)->prompt('Hello', provider: 'groq');

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-Custom-Header'));
});
