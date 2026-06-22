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

test('store=false replays hosted tool_search items inline on the follow up request', function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
        'store' => false,
    ]]);

    Http::fake([
        'api.openai.com/*' => Http::sequence([
            Http::response([
                'id' => 'resp_search_1',
                'status' => 'completed',
                'model' => 'gpt-5.4',
                'output' => [
                    ['type' => 'tool_search_call', 'id' => 'ts_1', 'status' => 'completed', 'queries' => ['secret']],
                    ['type' => 'tool_search_output', 'id' => 'tso_1', 'tools' => ['DeferredTool']],
                    ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'DeferredTool', 'arguments' => '{}', 'status' => 'completed'],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            fakeOpenAiResponse('All done'),
        ]),
    ]);

    (new OpenAiToolSearchAgent)->prompt('Find the secret', provider: 'openai');

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);

    $followUp = json_decode($recorded[1][0]->body(), true);
    $input = collect($followUp['input']);

    expect($followUp)->not->toHaveKey('previous_response_id')
        ->and($followUp['store'] ?? null)->toBeFalse()
        ->and($input->contains(fn ($i) => ($i['type'] ?? null) === 'tool_search_call' && ($i['id'] ?? null) === 'ts_1'))
        ->toBeTrue('tool_search_call echoed back inline')
        ->and($input->contains(fn ($i) => ($i['type'] ?? null) === 'tool_search_output' && ($i['id'] ?? null) === 'tso_1'))
        ->toBeTrue('tool_search_output echoed back inline')
        ->and($input->contains(fn ($i) => ($i['type'] ?? null) === 'function_call' && ($i['call_id'] ?? null) === 'call_1'))
        ->toBeTrue('deferred tool function call resent')
        ->and($input->contains(fn ($i) => ($i['type'] ?? null) === 'function_call_output' && ($i['call_id'] ?? null) === 'call_1'))
        ->toBeTrue('deferred tool result included');
});

test('rejects a ToolSearch tool on a model older than gpt-5.4', function () {
    Http::fake(['*' => fakeOpenAiResponse('ok')]);

    expect(fn () => (new OpenAiToolSearchAgent)->prompt('Hi', provider: 'openai', model: 'gpt-5.1'))
        ->toThrow(RuntimeException::class, 'gpt-5.1');

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
