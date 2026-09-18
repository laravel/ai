<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Tests\Fixtures\Agents\OpenAiAgent;

beforeEach(function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('provider tool output items land on the step', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'id' => 'resp_1',
            'status' => 'completed',
            'model' => 'gpt-5.4',
            'output' => [
                ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed', 'action' => ['type' => 'search', 'query' => 'laravel ai']],
                ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'ignored', 'arguments' => '{}'],
                ['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Found it.']]],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]),
    ]);

    $response = (new OpenAiAgent)->prompt('Search');

    expect($response->steps[0]->providerToolCalls)->toHaveCount(1)
        ->and($response->steps[0]->providerToolCalls[0])->toBeInstanceOf(ProviderToolCall::class)
        ->and($response->steps[0]->providerToolCalls[0]->toArray())->toBe([
            'id' => 'ws_1',
            'type' => 'web_search_call',
            'data' => ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed', 'action' => ['type' => 'search', 'query' => 'laravel ai']],
        ]);
});

test('streamed provider tool items land on the step', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(
            body: $this->ssePayload([
                $this->responseCreated(),
                ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed']],
                $this->outputTextDelta('Found it.'),
                $this->outputTextDone('Found it.'),
                ['type' => 'response.completed', 'response' => [
                    'id' => 'resp_1',
                    'model' => 'gpt-5.4',
                    'status' => 'completed',
                    'output' => [['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed']],
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $streamEnd = collect(iterator_to_array((new OpenAiAgent)->stream('Search')))->first(fn ($event): bool => $event instanceof StreamEnd);

    expect(array_map(fn (ProviderToolCall $call): string => $call->id, $streamEnd->steps[0]->providerToolCalls))->toBe(['ws_1']);
});
