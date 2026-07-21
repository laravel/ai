<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Agents\OpenAiToolSearchAgent;
use Tests\Fixtures\Tools\NonStrictTool;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('an agent with a deferred tool emits a tool_search entry and defers that tool', function () {
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

test('rejects a deferred tool when response storage is disabled', function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
        'store' => false,
    ]]);

    Http::fake(['*' => fakeOpenAiResponse('ok')]);

    expect(fn () => (new OpenAiToolSearchAgent)->prompt('Find the secret', provider: 'openai'))
        ->toThrow(LogicException::class, 'store=false');

    Http::assertNothingSent();
});

test('an agent with no deferred tools omits the tool_search entry', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('ok'),
    ]);

    $agent = new class implements Agent, HasTools
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function tools(): iterable
        {
            return [new NonStrictTool];
        }
    };

    $agent->prompt('Hi', provider: 'openai', model: 'gpt-5.4');

    Http::assertSent(function (Request $request) {
        $tools = collect(data_get(json_decode($request->body(), true), 'tools'));

        return ! $tools->contains(fn ($t) => ($t['type'] ?? null) === 'tool_search')
            && ! $tools->contains(fn ($t) => isset($t['defer_loading']));
    });
});
