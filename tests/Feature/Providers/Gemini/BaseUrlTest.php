<?php

use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Providers\Gemini\GeminiHelpers;

uses(GeminiHelpers::class);

test('gemini requests use the configured base url', function () {
    config(['ai.providers.gemini.url' => 'https://custom-proxy.example.com/v1']);

    Http::fake([
        'custom-proxy.example.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'https://custom-proxy.example.com/v1');
    });
});

test('gemini requests fall back to the default base url', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'generativelanguage.googleapis.com');
    });
});
