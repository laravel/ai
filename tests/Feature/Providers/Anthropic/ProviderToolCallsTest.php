<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Tests\Fixtures\Agents\AssistantAgent;

test('server tool use and result blocks land on the step keyed by the tool use', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'laravel ai']],
                ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => []],
                ['type' => 'text', 'text' => 'Found it.'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $response = (new AssistantAgent)->prompt('Search', provider: 'anthropic');

    expect(array_map(fn (ProviderToolCall $call): array => [$call->id, $call->type], $response->steps[0]->providerToolCalls))
        ->toBe([['srvtoolu_1', 'server_tool_use'], ['srvtoolu_1', 'web_search_tool_result']])
        ->and($response->steps[0]->providerToolCalls[0]->data['input'])->toBe(['query' => 'laravel ai']);
});
