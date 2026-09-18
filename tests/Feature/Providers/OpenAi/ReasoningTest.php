<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\OpenAiAgent;
use Tests\Fixtures\Agents\RememberingApprovableAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function (): void {
    config(['ai.providers.openai' => [...config('ai.providers.openai'), 'key' => 'test-key']]);
});

test('prompt joins reasoning blocks exactly as a stream of the same reasoning does', function (): void {
    $items = [
        openAiReasoningItem('rs_1', 'Let me ', 'think...'),
        openAiReasoningItem('rs_2', 'Now I am sure.'),
    ];

    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiReasonedResponse($items, 'Answer'),
            Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'Let me ', 'item_id' => 'rs_1'],
                    ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'think...', 'item_id' => 'rs_1'],
                    ['type' => 'response.output_item.done', 'item' => $items[0]],
                    ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'Now I am sure.', 'item_id' => 'rs_2'],
                    ['type' => 'response.output_item.done', 'item' => $items[1]],
                    $this->outputTextDelta('Answer'),
                    $this->outputTextDone('Answer'),
                    $this->responseCompleted(1, 1),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $prompted = (new OpenAiAgent)->prompt('Hello');

    $streamed = (new OpenAiAgent)->stream('Hello');
    iterator_to_array($streamed);

    expect($prompted->reasoning)->toBe("Let me think...\n\nNow I am sure.")
        ->and($prompted->reasoning)->toBe($streamed->reasoning);
});

test('prompt reads raw reasoning text exactly as a stream of the same reasoning does', function (): void {
    $item = openAiReasoningTextItem('rs_1', 'Raw ', 'thoughts.');

    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiReasonedResponse([$item], 'Answer'),
            Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    ['type' => 'response.reasoning_text.delta', 'delta' => 'Raw ', 'item_id' => 'rs_1'],
                    ['type' => 'response.reasoning_text.delta', 'delta' => 'thoughts.', 'item_id' => 'rs_1'],
                    ['type' => 'response.output_item.done', 'item' => $item],
                    $this->outputTextDelta('Answer'),
                    $this->outputTextDone('Answer'),
                    $this->responseCompleted(1, 1),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $prompted = (new OpenAiAgent)->prompt('Hello');

    $streamed = (new OpenAiAgent)->stream('Hello');
    iterator_to_array($streamed);

    expect($prompted->reasoning)->toBe('Raw thoughts.')
        ->and($prompted->reasoning)->toBe($streamed->reasoning);
});

test('a summary and the raw reasoning of one item stay separate blocks', function (): void {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiReasonedResponse([
            openAiReasoningItemWithBoth('rs_1', 'I weighed the options.', 'Raw thoughts.'),
        ], 'Answer'),
    ]);

    expect((new OpenAiAgent)->prompt('Hello')->reasoning)->toBe("I weighed the options.\n\nRaw thoughts.");
});

test('reasoning is joined across every step of a tool calling turn', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiReasonedToolCallResponse([openAiReasoningItem('rs_0', 'I need a number.')]),
            fakeOpenAiReasonedResponse([openAiReasoningItem('rs_1', 'The tool answered.')], 'The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

    expect($response->reasoning)->toBe("I need a number.\n\nThe tool answered.")
        ->and($response->steps->pluck('reasoning')->all())->toBe(['I need a number.', 'The tool answered.']);
});

test('a structured response carries the reasoning that produced it', function (): void {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiReasonedResponse(
            [openAiReasoningItem('rs_1', 'Gold is Au.')],
            '{"symbol": "Au"}',
        ),
    ]);

    $response = (new StructuredAgent)->prompt('Symbol for gold?', provider: 'openai');

    expect($response->reasoning)->toBe('Gold is Au.')
        ->and($response->structured)->toBe(['symbol' => 'Au']);
});

test('a response paused on a tool approval carries the reasoning so far', function (): void {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'id' => 'resp_tool_123',
            'status' => 'completed',
            'model' => 'gpt-5.4',
            'output' => [
                openAiReasoningItem('rs_1', 'I need approval first.'),
                [
                    'type' => 'function_call',
                    'id' => 'fc_123',
                    'call_id' => 'call_123',
                    'name' => 'ApprovableNumberGenerator',
                    'arguments' => '{}',
                    'status' => 'completed',
                ],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $paused = (new RememberingApprovableAgent)
        ->forUser((object) ['id' => 1])
        ->prompt('Generate a number', provider: 'openai');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->reasoning)->toBe('I need approval first.');
});
