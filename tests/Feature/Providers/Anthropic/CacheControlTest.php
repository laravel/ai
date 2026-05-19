<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\CachingAgent;

test('system prompt is sent as a cached block', function () {
    Http::fake(['api.anthropic.com/*' => $this->fakeTextResponse()]);

    (new CachingAgent)->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $system = $request->data()['system'];

        return is_array($system)
            && $system[0]['type'] === 'text'
            && $system[0]['cache_control'] === ['type' => 'ephemeral'];
    });
});

test('cache directive does not appear in the request body', function () {
    Http::fake(['api.anthropic.com/*' => $this->fakeTextResponse()]);

    (new CachingAgent)->prompt('Hi', provider: 'anthropic');

    Http::assertSent(fn ($request) => ! array_key_exists('cache', $request->data()));
});

test('system stays a plain string without a cache directive', function () {
    Http::fake(['api.anthropic.com/*' => $this->fakeTextResponse()]);

    (new AssistantAgent)->prompt('Hi', provider: 'anthropic');

    Http::assertSent(fn ($request) => is_string($request->data()['system']));
});
