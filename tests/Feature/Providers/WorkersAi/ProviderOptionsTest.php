<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ProviderOptionsAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

test('provider options are forwarded in request body', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    (new ProviderOptionsAgent)->prompt('Hello', provider: 'workersai');

    Http::assertSentCount(1);
});

test('session affinity is sent as header not body', function () {
    config(['ai.providers.workersai.session_affinity' => 'ses_test-123']);

    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    \Laravel\Ai\agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('x-session-affinity', 'ses_test-123')
            && ! array_key_exists('session_affinity', $body);
    });
});

test('provider options are preserved in tool call follow up', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::sequence([
            Http::response(fakeWorkersAiToolCallResponse()),
            Http::response(workersAiTextResponse('Done')),
        ]),
    ]);

    (new ProviderOptionsWithToolsAgent)->prompt('Generate a number', provider: 'workersai');

    $requests = Http::recorded();

    expect(count($requests))->toBeGreaterThanOrEqual(2);
});
