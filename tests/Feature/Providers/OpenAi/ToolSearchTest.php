<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\OpenAiToolSearchAgent;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('an agent with a ToolSearch tool emits a tool_search entry and defers its nested tools', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('ok'),
    ]);

    (new OpenAiToolSearchAgent)->prompt('Hi');

    Http::assertSent(function (Request $request) {
        $tools = collect(data_get(json_decode($request->body(), true), 'tools'));

        $deferred = $tools->firstWhere('name', 'DeferredTool');
        $plain = $tools->firstWhere('name', 'NonStrictTool');

        return $tools->contains(fn ($t) => ($t['type'] ?? null) === 'tool_search')
            && ($deferred['defer_loading'] ?? false) === true
            && ! isset($plain['defer_loading']);
    });
});
