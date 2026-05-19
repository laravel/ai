<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\MultiStepToolAgent;
use Tests\Fixtures\Agents\OpenAiAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
        'encrypted_reasoning' => true,
    ]]);
});

test('initial request includes store false and reasoning encrypted content in include', function () {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse('Hello'),
    ]);

    (new OpenAiAgent)->prompt('Hello');

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return ($body['store'] ?? null) === false
            && in_array('reasoning.encrypted_content', $body['include'] ?? [], true);
    });
});

test('tool follow up omits previous response id and echoes encrypted reasoning back inline', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiToolCallResponseWithEncryptedReasoning('rs_1', 'enc-blob-1', 'fc_1', 'call_1'),
            fakeOpenAiResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);

    $followUp = json_decode($recorded[1][0]->body(), true);

    expect($followUp)->not->toHaveKey('previous_response_id')
        ->and($followUp['store'] ?? null)->toBeFalse()
        ->and($followUp['include'] ?? [])->toContain('reasoning.encrypted_content');

    $input = collect($followUp['input']);

    expect($input->contains(fn ($i) => ($i['role'] ?? null) === 'user'
        && collect($i['content'] ?? [])->contains(fn ($c) => ($c['text'] ?? '') === 'Generate a number')))
        ->toBeTrue('original user message resent inline')
        ->and($input->contains(fn ($i) => ($i['type'] ?? null) === 'reasoning'
            && ($i['id'] ?? null) === 'rs_1'
            && ($i['encrypted_content'] ?? null) === 'enc-blob-1'))
        ->toBeTrue('reasoning block with encrypted_content round-tripped')
        ->and($input->contains(fn ($i) => ($i['type'] ?? null) === 'function_call'
            && ($i['call_id'] ?? null) === 'call_1'))
        ->toBeTrue('assistant function call resent')
        ->and($input->contains(fn ($i) => ($i['type'] ?? null) === 'function_call_output'
            && ($i['call_id'] ?? null) === 'call_1'))
        ->toBeTrue('tool result included');
});

test('multi step tool loop accumulates encrypted reasoning across steps', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiToolCallResponseWithEncryptedReasoning('rs_1', 'enc-blob-1', 'fc_1', 'call_1'),
            fakeOpenAiToolCallResponseWithEncryptedReasoning('rs_2', 'enc-blob-2', 'fc_2', 'call_2'),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    (new MultiStepToolAgent)->prompt('Generate a number', provider: 'openai');

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(3);

    $finalFollowUp = json_decode($recorded[2][0]->body(), true);

    expect($finalFollowUp)->not->toHaveKey('previous_response_id');

    $input = collect($finalFollowUp['input']);

    expect($input->where(fn ($i) => ($i['type'] ?? null) === 'reasoning'
        && ($i['encrypted_content'] ?? null) === 'enc-blob-1')->count())->toBe(1)
        ->and($input->where(fn ($i) => ($i['type'] ?? null) === 'reasoning'
            && ($i['encrypted_content'] ?? null) === 'enc-blob-2')->count())->toBe(1)
        ->and($input->where(fn ($i) => ($i['type'] ?? null) === 'function_call_output')->count())->toBe(2);
});

test('streaming tool follow up echoes encrypted reasoning back inline', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    [
                        'type' => 'response.output_item.done',
                        'item' => [
                            'type' => 'reasoning',
                            'id' => 'rs_1',
                            'summary' => [],
                            'encrypted_content' => 'enc-blob-1',
                        ],
                    ],
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

    $events = [];
    foreach ((new ProviderOptionsWithToolsAgent)->stream('Generate a number', provider: 'openai') as $event) {
        $events[] = $event;
    }

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);

    $followUp = json_decode($recorded[1][0]->body(), true);

    expect($followUp)->not->toHaveKey('previous_response_id')
        ->and($followUp['store'] ?? null)->toBeFalse()
        ->and($followUp['include'] ?? [])->toContain('reasoning.encrypted_content');

    $input = collect($followUp['input']);

    expect($input->contains(fn ($i) => ($i['type'] ?? null) === 'reasoning'
        && ($i['id'] ?? null) === 'rs_1'
        && ($i['encrypted_content'] ?? null) === 'enc-blob-1'))
        ->toBeTrue('streamed reasoning block with encrypted_content round-tripped')
        ->and($input->contains(fn ($i) => ($i['type'] ?? null) === 'function_call'
            && ($i['call_id'] ?? null) === 'call_1'))
        ->toBeTrue('streamed function call resent')
        ->and($input->contains(fn ($i) => ($i['type'] ?? null) === 'function_call_output'
            && ($i['call_id'] ?? null) === 'call_1'))
        ->toBeTrue('streamed tool result included');
});

test('default encrypted reasoning off preserves previous response id behaviour', function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'encrypted_reasoning' => false,
    ]]);

    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiToolCallResponseWithEncryptedReasoning('rs_1', 'enc-blob-1', 'fc_1', 'call_1'),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

    $recorded = Http::recorded();
    $followUp = json_decode($recorded[1][0]->body(), true);

    expect($followUp)->toHaveKey('previous_response_id')
        ->and($followUp['previous_response_id'])->toBe('resp_tool_1')
        ->and($followUp)->not->toHaveKey('store')
        ->and($followUp)->not->toHaveKey('include');
});

function fakeOpenAiToolCallResponseWithEncryptedReasoning(string $reasoningId, string $encryptedContent, string $functionCallId, string $callId): PromiseInterface
{
    return Http::response([
        'id' => 'resp_tool_1',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [
            [
                'type' => 'reasoning',
                'id' => $reasoningId,
                'summary' => [],
                'encrypted_content' => $encryptedContent,
            ],
            [
                'type' => 'function_call',
                'id' => $functionCallId,
                'call_id' => $callId,
                'name' => 'FixedNumberGenerator',
                'arguments' => '{}',
                'status' => 'completed',
            ],
        ],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ]);
}
