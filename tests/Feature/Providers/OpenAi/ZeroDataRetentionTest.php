<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\MultiStepToolAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
        'zero_data_retention' => true,
    ]]);
});

test('zero data retention rebuilds tool follow up without previous_response_id', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            Http::response([
                'id' => 'resp_tool_1',
                'status' => 'completed',
                'model' => 'gpt-5.4',
                'output' => [[
                    'type' => 'function_call',
                    'id' => 'fc_1',
                    'call_id' => 'call_1',
                    'name' => 'FixedNumberGenerator',
                    'arguments' => '{}',
                    'status' => 'completed',
                ]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            fakeOpenAiResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'openai',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUp = json_decode($recorded[1][0]->body(), true);

    expect($followUp)->not->toHaveKey('previous_response_id');

    $input = collect($followUp['input']);

    expect($input->contains(fn ($input) => ($input['role'] ?? null) === 'system'))->toBeTrue('system instructions resent')
        ->and($input->contains(fn ($input) => ($input['role'] ?? null) === 'user'
            && collect($input['content'] ?? [])->contains(fn ($c) => ($c['text'] ?? '') === 'Generate a random number')))->toBeTrue('user message resent')
        ->and($input->contains(fn ($input) => ($input['type'] ?? null) === 'function_call'
            && ($input['call_id'] ?? null) === 'call_1'))->toBeTrue('assistant tool call resent')
        ->and($input->contains(fn ($input) => ($input['type'] ?? null) === 'function_call_output'
            && ($input['call_id'] ?? null) === 'call_1'))->toBeTrue('tool result included');
});

test('zero data retention accumulates context across multiple tool steps', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            Http::response([
                'id' => 'resp_tool_1',
                'status' => 'completed',
                'model' => 'gpt-5.4',
                'output' => [[
                    'type' => 'function_call',
                    'id' => 'fc_1',
                    'call_id' => 'call_1',
                    'name' => 'FixedNumberGenerator',
                    'arguments' => '{}',
                    'status' => 'completed',
                ]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'resp_tool_2',
                'status' => 'completed',
                'model' => 'gpt-5.4',
                'output' => [[
                    'type' => 'function_call',
                    'id' => 'fc_2',
                    'call_id' => 'call_2',
                    'name' => 'FixedNumberGenerator',
                    'arguments' => '{}',
                    'status' => 'completed',
                ]],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 5],
            ]),
            fakeOpenAiResponse('The number is 72019'),
        ]),
    ]);

    (new MultiStepToolAgent)->prompt(
        'Generate a random number',
        provider: 'openai',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(3);

    $secondFollowUp = json_decode($recorded[2][0]->body(), true);

    expect($secondFollowUp)->not->toHaveKey('previous_response_id');

    $input = collect($secondFollowUp['input']);

    expect($input->where(fn ($input) => ($input['role'] ?? null) === 'user'
        && collect($input['content'] ?? [])->contains(fn ($c) => ($c['text'] ?? '') === 'Generate a random number'))->count())
        ->toBe(1, 'original user message present exactly once')
        ->and($input->where(fn ($input) => ($input['type'] ?? null) === 'function_call')->count())
        ->toBe(2, 'both assistant tool calls included')
        ->and($input->where(fn ($input) => ($input['type'] ?? null) === 'function_call_output')->count())
        ->toBe(2, 'both tool results included')
        ->and($input->contains(fn ($input) => ($input['type'] ?? null) === 'function_call' && ($input['call_id'] ?? null) === 'call_1'))->toBeTrue()
        ->and($input->contains(fn ($input) => ($input['type'] ?? null) === 'function_call' && ($input['call_id'] ?? null) === 'call_2'))->toBeTrue()
        ->and($input->contains(fn ($input) => ($input['type'] ?? null) === 'function_call_output' && ($input['call_id'] ?? null) === 'call_1'))->toBeTrue()
        ->and($input->contains(fn ($input) => ($input['type'] ?? null) === 'function_call_output' && ($input['call_id'] ?? null) === 'call_2'))->toBeTrue();
});

test('zero data retention rebuilds streaming tool follow up without previous_response_id', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputItemAdded('fc_1', 'call_1', 'FixedNumberGenerator'),
                    $this->functionCallArgumentsDelta('fc_1', '{}'),
                    $this->functionCallArgumentsDone('fc_1', '{}'),
                    $this->responseCompleted(10, 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: $this->ssePayload([
                    [
                        'type' => 'response.created',
                        'response' => ['id' => 'resp_2', 'model' => 'gpt-5.4', 'status' => 'in_progress', 'output' => []],
                    ],
                    $this->outputTextDelta('Done'),
                    $this->outputTextDone('Done'),
                    $this->responseCompleted(20, 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $agent = new ProviderOptionsWithToolsAgent;

    $events = [];

    foreach ($agent->stream('Generate a random number', provider: 'openai') as $event) {
        $events[] = $event;
    }

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUp = json_decode($recorded[1][0]->body(), true);

    expect($followUp)->not->toHaveKey('previous_response_id');

    $input = collect($followUp['input']);

    expect($input->contains(fn ($input) => ($input['role'] ?? null) === 'system'))->toBeTrue('system instructions resent')
        ->and($input->contains(fn ($input) => ($input['role'] ?? null) === 'user'
            && collect($input['content'] ?? [])->contains(fn ($c) => ($c['text'] ?? '') === 'Generate a random number')))->toBeTrue('user message resent')
        ->and($input->contains(fn ($input) => ($input['type'] ?? null) === 'function_call'
            && ($input['call_id'] ?? null) === 'call_1'))->toBeTrue('assistant tool call resent')
        ->and($input->contains(fn ($input) => ($input['type'] ?? null) === 'function_call_output'
            && ($input['call_id'] ?? null) === 'call_1'))->toBeTrue('tool result included');
});

test('zero data retention accumulates streaming context across multiple tool steps', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputItemAdded('fc_1', 'call_1', 'FixedNumberGenerator'),
                    $this->functionCallArgumentsDelta('fc_1', '{}'),
                    $this->functionCallArgumentsDone('fc_1', '{}'),
                    $this->responseCompleted(10, 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: $this->ssePayload([
                    [
                        'type' => 'response.created',
                        'response' => ['id' => 'resp_2', 'model' => 'gpt-5.4', 'status' => 'in_progress', 'output' => []],
                    ],
                    $this->outputItemAdded('fc_2', 'call_2', 'FixedNumberGenerator'),
                    $this->functionCallArgumentsDelta('fc_2', '{}'),
                    $this->functionCallArgumentsDone('fc_2', '{}'),
                    $this->responseCompleted(12, 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: $this->ssePayload([
                    [
                        'type' => 'response.created',
                        'response' => ['id' => 'resp_3', 'model' => 'gpt-5.4', 'status' => 'in_progress', 'output' => []],
                    ],
                    $this->outputTextDelta('Done'),
                    $this->outputTextDone('Done'),
                    $this->responseCompleted(20, 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $agent = new MultiStepToolAgent;

    foreach ($agent->stream('Generate a random number', provider: 'openai') as $event) {
        // drain the stream
    }

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(3);

    $secondFollowUp = json_decode($recorded[2][0]->body(), true);

    expect($secondFollowUp)->not->toHaveKey('previous_response_id');

    $input = collect($secondFollowUp['input']);

    expect($input->where(fn ($input) => ($input['role'] ?? null) === 'user'
        && collect($input['content'] ?? [])->contains(fn ($c) => ($c['text'] ?? '') === 'Generate a random number'))->count())
        ->toBe(1, 'original user message present exactly once')
        ->and($input->where(fn ($input) => ($input['type'] ?? null) === 'function_call')->count())
        ->toBe(2, 'both assistant tool calls included')
        ->and($input->where(fn ($input) => ($input['type'] ?? null) === 'function_call_output')->count())
        ->toBe(2, 'both tool results included')
        ->and($input->contains(fn ($input) => ($input['type'] ?? null) === 'function_call' && ($input['call_id'] ?? null) === 'call_1'))->toBeTrue()
        ->and($input->contains(fn ($input) => ($input['type'] ?? null) === 'function_call' && ($input['call_id'] ?? null) === 'call_2'))->toBeTrue()
        ->and($input->contains(fn ($input) => ($input['type'] ?? null) === 'function_call_output' && ($input['call_id'] ?? null) === 'call_1'))->toBeTrue()
        ->and($input->contains(fn ($input) => ($input['type'] ?? null) === 'function_call_output' && ($input['call_id'] ?? null) === 'call_2'))->toBeTrue();
});
