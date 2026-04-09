<?php

namespace Tests\Feature\Providers\Anthropic;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

trait AnthropicHelpers
{
    protected function fakeTextResponse(string $text = 'Hello'): PromiseInterface
    {
        return Http::response([
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
    }

    protected function fakeToolCallResponse(string $toolName = 'FixedNumberGenerator'): PromiseInterface
    {
        return Http::response([
            'id' => 'msg_tool_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_123',
                'name' => $toolName,
                'input' => (object) [],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
    }

    protected function fakeStructuredResponse(array $data): PromiseInterface
    {
        return Http::response([
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_123',
                'name' => 'output_structured_data',
                'input' => $data,
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
    }
}
