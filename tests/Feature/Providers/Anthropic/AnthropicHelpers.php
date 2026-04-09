<?php

namespace Tests\Feature\Providers\Anthropic;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\AssistantAgent;

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

    protected function fakeUniqueToolCallResponse(): PromiseInterface
    {
        return Http::response([
            'id' => 'msg_tool_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_'.uniqid(),
                'name' => 'FixedNumberGenerator',
                'input' => (object) [],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
    }

    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream(
            'Hello',
            provider: 'anthropic',
        );

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        return $events;
    }

    protected function ssePayload(array $events): string
    {
        $lines = [];

        foreach ($events as $event) {
            $lines[] = 'data: '.json_encode($event);
        }

        return implode("\n\n", $lines)."\n\n";
    }

    protected function messageStart(): array
    {
        return [
            'type' => 'message_start',
            'message' => [
                'id' => 'msg_1',
                'model' => 'claude-sonnet-4-6',
                'role' => 'assistant',
                'content' => [],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
            ],
        ];
    }

    protected function contentBlockStart(int $index, array $contentBlock): array
    {
        return [
            'type' => 'content_block_start',
            'index' => $index,
            'content_block' => $contentBlock,
        ];
    }

    protected function contentBlockDelta(int $index, array $delta): array
    {
        return [
            'type' => 'content_block_delta',
            'index' => $index,
            'delta' => $delta,
        ];
    }

    protected function contentBlockStop(int $index): array
    {
        return [
            'type' => 'content_block_stop',
            'index' => $index,
        ];
    }

    protected function messageDelta(string $stopReason, int $outputTokens): array
    {
        return [
            'type' => 'message_delta',
            'delta' => ['stop_reason' => $stopReason],
            'usage' => ['output_tokens' => $outputTokens],
        ];
    }
}
