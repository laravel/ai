<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

test('anthropic requests use the configured base url', function () {
    config(['ai.providers.anthropic.url' => 'https://custom-proxy.example.com/v1']);

    Http::fake([
        'custom-proxy.example.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        return $request->url() === 'https://custom-proxy.example.com/v1/messages';
    });
});

test('anthropic requests fall back to the default base url', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.anthropic.com/v1/messages';
    });
});
