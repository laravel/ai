<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Messages\AssistantMessage;
use Tests\Fixtures\Agents\MultiStepToolAgent;
use Tests\Fixtures\Agents\OpenAiAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
        'store' => false,
    ]]);
});

test('initial request includes store false and reasoning encrypted content in include', function (): void {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse('Hello'),
    ]);

    (new OpenAiAgent)->prompt('Hello');

    Http::assertSent(function ($request): bool {
        $body = json_decode((string) $request->body(), true);

        return ($body['store'] ?? null) === false
            && in_array('reasoning.encrypted_content', $body['include'] ?? [], true);
    });
});

test('tool follow up omits previous response id and echoes encrypted reasoning back inline', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiToolCallResponseWithEncryptedReasoning('rs_1', 'enc-blob-1', 'fc_1', 'call_1'),
            fakeOpenAiResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);

    $followUp = json_decode((string) $recorded[1][0]->body(), true);

    expect($followUp)->not->toHaveKey('previous_response_id')
        ->and($followUp['store'] ?? null)->toBeFalse()
        ->and($followUp['include'] ?? [])->toContain('reasoning.encrypted_content');

    $input = collect($followUp['input']);

    expect($input->contains(fn ($i): bool => ($i['role'] ?? null) === 'user'
        && collect($i['content'] ?? [])->contains(fn ($c): bool => ($c['text'] ?? '') === 'Generate a number')))
        ->toBeTrue('original user message resent inline')
        ->and($input->contains(fn ($i): bool => ($i['type'] ?? null) === 'reasoning'
            && ($i['id'] ?? null) === 'rs_1'
            && ($i['encrypted_content'] ?? null) === 'enc-blob-1'))
        ->toBeTrue('reasoning block with encrypted_content round-tripped')
        ->and($input->contains(fn ($i): bool => ($i['type'] ?? null) === 'function_call'
            && ($i['call_id'] ?? null) === 'call_1'))
        ->toBeTrue('assistant function call resent')
        ->and($input->contains(fn ($i): bool => ($i['type'] ?? null) === 'function_call_output'
            && ($i['call_id'] ?? null) === 'call_1'))
        ->toBeTrue('tool result included');
});

test('multi step tool loop accumulates encrypted reasoning across steps', function (): void {
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

    $finalFollowUp = json_decode((string) $recorded[2][0]->body(), true);

    expect($finalFollowUp)->not->toHaveKey('previous_response_id');

    $input = collect($finalFollowUp['input']);

    expect($input->where(fn ($i): bool => ($i['type'] ?? null) === 'reasoning'
        && ($i['encrypted_content'] ?? null) === 'enc-blob-1')->count())->toBe(1)
        ->and($input->where(fn ($i): bool => ($i['type'] ?? null) === 'reasoning'
            && ($i['encrypted_content'] ?? null) === 'enc-blob-2')->count())->toBe(1)
        ->and($input->where(fn ($i): bool => ($i['type'] ?? null) === 'function_call_output')->count())->toBe(2);
});

test('streaming tool follow up echoes encrypted reasoning back inline', function (): void {
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
                    $this->responseCompleted(10, 5, output: [
                        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'enc-blob-1'],
                        ['type' => 'function_call', 'status' => 'completed', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}'],
                    ]),
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

    $followUp = json_decode((string) $recorded[1][0]->body(), true);

    expect($followUp)->not->toHaveKey('previous_response_id')
        ->and($followUp['store'] ?? null)->toBeFalse()
        ->and($followUp['include'] ?? [])->toContain('reasoning.encrypted_content');

    $input = collect($followUp['input']);

    expect($input->contains(fn ($i): bool => ($i['type'] ?? null) === 'reasoning'
        && ($i['id'] ?? null) === 'rs_1'
        && ($i['encrypted_content'] ?? null) === 'enc-blob-1'))
        ->toBeTrue('streamed reasoning block with encrypted_content round-tripped')
        ->and($input->contains(fn ($i): bool => ($i['type'] ?? null) === 'function_call'
            && ($i['call_id'] ?? null) === 'call_1'))
        ->toBeTrue('streamed function call resent')
        ->and($input->contains(fn ($i): bool => ($i['type'] ?? null) === 'function_call_output'
            && ($i['call_id'] ?? null) === 'call_1'))
        ->toBeTrue('streamed tool result included');
});

test('non-reasoning model omits reasoning.encrypted_content include even with store false', function (string $model): void {
    Http::fake(['api.openai.com/*' => fakeOpenAiResponse()]);

    (new OpenAiAgent)->prompt('Hi', model: $model);

    Http::assertSent(function ($request): bool {
        $body = json_decode((string) $request->body(), true);

        return ($body['store'] ?? null) === false
            && ! in_array('reasoning.encrypted_content', $body['include'] ?? [], true);
    });
})->with([
    'gpt-4.1',
    'gpt-4o',
    'gpt-5-chat-latest',
]);

test('store accepts env-style string values', function (mixed $storeValue, bool $shouldBeStateless): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'store' => $storeValue,
    ]]);

    Http::fake(['api.openai.com/*' => fakeOpenAiResponse()]);

    (new OpenAiAgent)->prompt('Hi');

    Http::assertSent(function ($request) use ($shouldBeStateless): bool {
        $body = json_decode((string) $request->body(), true);
        $isStateless = ($body['store'] ?? null) === false;

        return $isStateless === $shouldBeStateless;
    });
})->with([
    'bool false' => [false, true],
    'string "false"' => ['false', true],
    'string "0"' => ['0', true],
    'string "no"' => ['no', true],
    'bool true' => [true, false],
    'string "true"' => ['true', false],
    'unrecognized string' => ['maybe', false],
]);

test('default store true preserves previous response id behaviour', function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'store' => true,
    ]]);

    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiToolCallResponseWithEncryptedReasoning('rs_1', 'enc-blob-1', 'fc_1', 'call_1'),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

    $recorded = Http::recorded();
    $followUp = json_decode((string) $recorded[1][0]->body(), true);

    expect($followUp)->toHaveKey('previous_response_id')
        ->and($followUp['previous_response_id'])->toBe('resp_tool_1')
        ->and($followUp)->not->toHaveKey('store')
        ->and($followUp)->not->toHaveKey('include');

    $input = collect($followUp['input']);

    expect($input)->toHaveCount(1)
        ->and($input->first()['type'] ?? null)->toBe('function_call_output')
        ->and($input->first()['call_id'] ?? null)->toBe('call_1')
        ->and($input->contains(fn ($i): bool => ($i['role'] ?? null) === 'user'))->toBeFalse()
        ->and($input->contains(fn ($i): bool => ($i['type'] ?? null) === 'reasoning'))->toBeFalse();
});

test('stateless replay preserves the original order of reasoning, hosted tool, and function call items', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            Http::response([
                'id' => 'resp_tool_1',
                'status' => 'completed',
                'model' => 'gpt-5.4',
                'output' => [
                    ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'enc-blob-1'],
                    ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed'],
                    ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}', 'status' => 'completed'],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

    $input = collect(json_decode(Http::recorded()[1][0]->body(), true)['input']);

    $reasoningAt = $input->search(fn ($i) => ($i['type'] ?? null) === 'reasoning');
    $hostedAt = $input->search(fn ($i) => ($i['type'] ?? null) === 'web_search_call');
    $functionAt = $input->search(fn ($i) => ($i['type'] ?? null) === 'function_call');

    expect($reasoningAt)->not->toBeFalse()
        ->and($hostedAt)->not->toBeFalse()
        ->and($functionAt)->not->toBeFalse()
        ->and($reasoningAt)->toBeLessThan($hostedAt)
        ->and($hostedAt)->toBeLessThan($functionAt)
        ->and($input->firstWhere('type', 'reasoning')['encrypted_content'] ?? null)->toBe('enc-blob-1')
        ->and($input->contains(fn ($i) => ($i['type'] ?? null) === 'function_call_output'
            && ($i['call_id'] ?? null) === 'call_1'))->toBeTrue();
});

test('stateless (store=false) responses capture replay blocks', function () {
    Http::fake(['api.openai.com/*' => fakeOpenAiResponse('Hi')]);

    $response = (new OpenAiAgent)->prompt('Hello', provider: 'openai');

    expect($response->messages->whereInstanceOf(AssistantMessage::class)->last()->providerContentBlocks)
        ->not->toBeEmpty();
});

test('stateful (store=true) responses do not capture replay blocks', function () {
    config(['ai.providers.openai' => [...config('ai.providers.openai'), 'store' => true]]);

    Http::fake(['api.openai.com/*' => fakeOpenAiResponse('Hi')]);

    $response = (new OpenAiAgent)->prompt('Hello', provider: 'openai');

    expect($response->messages->whereInstanceOf(AssistantMessage::class)->last()->providerContentBlocks)
        ->toBeEmpty();
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
