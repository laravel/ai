<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Tests\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    config(['ai.providers.xai' => [
        ...config('ai.providers.xai'),
        'key' => 'test-key',
    ]]);
});

test('provider tool output items land on the step', function (): void {
    Http::fake([
        '*' => Http::response([
            'id' => 'resp_1',
            'status' => 'completed',
            'model' => 'grok-4',
            'output' => [
                ['type' => 'code_execution_call', 'id' => 'ce_1', 'status' => 'completed', 'code' => 'print(1)'],
                ['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => '1']]],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]),
    ]);

    $response = (new AssistantAgent)->prompt('Run it', provider: 'xai');

    expect(array_map(fn (ProviderToolCall $call): array => [$call->id, $call->type], $response->steps[0]->providerToolCalls))
        ->toBe([['ce_1', 'code_execution_call']]);
});
