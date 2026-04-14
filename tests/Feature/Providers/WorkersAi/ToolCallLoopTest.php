<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

test('tool calls trigger follow up request', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::sequence([
            Http::response(fakeWorkersAiToolCallResponse()),
            Http::response(workersAiTextResponse('The number is 72019')),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'workersai',
    );

    expect($response->text)->toBe('The number is 72019');

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);
});

test('max steps limits tool call depth', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(fakeWorkersAiToolCallResponse()),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'workersai',
    );

    $recorded = Http::recorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});
