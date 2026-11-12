<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Tests\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

function openAiRefusalMessage(): array
{
    return [
        'id' => 'msg_1',
        'type' => 'message',
        'status' => 'completed',
        'role' => 'assistant',
        'content' => [[
            'type' => 'refusal',
            'refusal' => "I'm sorry, I cannot assist with that request.",
        ]],
    ];
}

test('a refusal content part maps to ContentFilter', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'id' => 'resp_1',
            'status' => 'completed',
            'model' => 'gpt-5.4',
            'output' => [openAiRefusalMessage()],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 9,
            ],
        ]),
    ]);

    $response = (new AssistantAgent)->prompt('Hi', provider: 'openai');

    expect($response->steps->last()->finishReason)->toBe(FinishReason::ContentFilter)
        ->and($response->text)->toBe('');
});

test('a completed message with output text still maps to Stop', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'id' => 'resp_1',
            'status' => 'completed',
            'model' => 'gpt-5.4',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => 'Hello there']],
            ]],
            'usage' => [
                'input_tokens' => 1,
                'output_tokens' => 1,
            ],
        ]),
    ]);

    $response = (new AssistantAgent)->prompt('Hi', provider: 'openai');

    expect($response->steps->last()->finishReason)->toBe(FinishReason::Stop);
});

test('a streamed refusal maps to ContentFilter', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(
            body: $this->ssePayload([
                $this->responseCreated(),
                [
                    'type' => 'response.refusal.delta',
                    'item_id' => 'msg_1',
                    'output_index' => 0,
                    'content_index' => 0,
                    'delta' => "I'm sorry, I cannot assist with that request.",
                ],
                [
                    'type' => 'response.refusal.done',
                    'item_id' => 'msg_1',
                    'output_index' => 0,
                    'content_index' => 0,
                    'refusal' => "I'm sorry, I cannot assist with that request.",
                ],
                $this->responseCompleted(10, 9, output: [openAiRefusalMessage()]),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->reason)->toBe(FinishReason::ContentFilter->value);
});
