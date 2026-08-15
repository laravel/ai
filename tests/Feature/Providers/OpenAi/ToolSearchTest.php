<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ToolSearch;
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

test('rejects a ToolSearch tool when response storage is disabled', function () {
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

test('an agent whose only tool is an empty ToolSearch omits the tool fields', function () {
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
            return [new ToolSearch];
        }
    };

    $agent->prompt('Hi', provider: 'openai', model: 'gpt-5.4');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});
