<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\HeadersAgent;

test('custom headers are included in anthropic request', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new HeadersAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $request->hasHeader('X-Custom-Header', 'anthropic-value')
            && ! array_key_exists('X-Custom-Header', $body);
    });
});

test('request does not contain custom headers when agent does not implement interface', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(fn ($request): bool => ! $request->hasHeader('X-Custom-Header'));
});
