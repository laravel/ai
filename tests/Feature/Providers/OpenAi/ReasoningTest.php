<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\OpenAiAgent;
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

test('prompt drops blank reasoning blocks the way a stream does', function (): void {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiReasonedResponse([
            openAiReasoningItem('rs_1', '   '),
            openAiReasoningItem('rs_2', 'Real thinking.'),
        ], 'Answer'),
    ]);

    expect((new OpenAiAgent)->prompt('Hello')->reasoning)->toBe('Real thinking.');
});

test('reasoning is joined across every step of a tool calling turn', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiReasonedToolCallResponse([openAiReasoningItem('rs_0', 'I need a number.')]),
            fakeOpenAiReasonedResponse([openAiReasoningItem('rs_1', 'The tool answered.')], 'The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

    expect($response->reasoning)->toBe("I need a number.\n\nThe tool answered.");
});

test('a response without reasoning leaves the reasoning empty', function (): void {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse('Hello'),
    ]);

    expect((new OpenAiAgent)->prompt('Hello')->reasoning)->toBe('');
});
