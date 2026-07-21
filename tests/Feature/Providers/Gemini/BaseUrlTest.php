<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

test('gemini requests use the configured base url', function (): void {
    config(['ai.providers.gemini.url' => 'https://custom-proxy.example.com/v1']);

    Http::fake([
        'custom-proxy.example.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'https://custom-proxy.example.com/v1'));
});

test('gemini requests fall back to the default base url', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'generativelanguage.googleapis.com'));
});
