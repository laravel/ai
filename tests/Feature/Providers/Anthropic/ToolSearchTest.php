<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AnthropicToolSearchAgent;

test('an agent with a ProviderToolSearch tool emits the regex tool search entry and defers its nested tools', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    (new AnthropicToolSearchAgent)->prompt('Hi');

    Http::assertSent(function ($request) {
        $tools = collect($request->data()['tools'] ?? []);

        $deferred = $tools->firstWhere('name', 'DeferredTool');
        $plain = $tools->firstWhere('name', 'NonStrictTool');

        return $tools->contains(fn ($t) => ($t['type'] ?? null) === 'tool_search_tool_regex_20251119')
            && ($deferred['defer_loading'] ?? false) === true
            && ! isset($plain['defer_loading']);
    });
});
