<?php

use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tests\Feature\Providers\ClaudeCli\ClaudeCliHelpers;
use Tests\Fixtures\Agents\AssistantAgent;

uses(ClaudeCliHelpers::class);

afterEach(function (): void {
    $this->cleanUpFakeCliScripts();
});

describe('multi-turn text accumulation', function (): void {
    /**
     * The Claude CLI handles tool calls internally and can emit multiple assistant
     * messages before the result event. Verify that text from all assistant turns
     * is accumulated and returned in the final response.
     */
    test('accumulates text from multiple assistant turns', function (): void {
        $lines = [
            [
                'type' => 'system',
                'subtype' => 'init',
                'session_id' => 'sess-multi',
                'tools' => [],
                'mcp_servers' => [],
            ],
            [
                'type' => 'assistant',
                'message' => [
                    'id' => 'msg_01',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-5-5',
                    'content' => [
                        ['type' => 'text', 'text' => 'First turn. '],
                    ],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ],
            ],
            [
                'type' => 'assistant',
                'message' => [
                    'id' => 'msg_02',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-5-5',
                    'content' => [
                        ['type' => 'text', 'text' => 'Second turn.'],
                    ],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 15, 'output_tokens' => 4],
                ],
            ],
            [
                'type' => 'result',
                'subtype' => 'success',
                'result' => 'First turn. Second turn.',
                'session_id' => 'sess-multi',
            ],
        ];

        $binary = $this->fakeCli($lines);
        $this->useCliBinary($binary);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'claude-cli');

        expect($response->text)->toBe('First turn. Second turn.');
    });

    test('stream path accumulates text from multiple assistant turns', function (): void {
        $lines = [
            [
                'type' => 'system',
                'subtype' => 'init',
                'session_id' => 'sess-stream-multi',
                'tools' => [],
                'mcp_servers' => [],
            ],
            [
                'type' => 'assistant',
                'message' => [
                    'id' => 'msg_01',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-5-5',
                    'content' => [
                        ['type' => 'text', 'text' => 'Alpha '],
                    ],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 3],
                ],
            ],
            [
                'type' => 'assistant',
                'message' => [
                    'id' => 'msg_02',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-5-5',
                    'content' => [
                        ['type' => 'text', 'text' => 'Beta'],
                    ],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 15, 'output_tokens' => 2],
                ],
            ],
            [
                'type' => 'result',
                'subtype' => 'success',
                'result' => 'Alpha Beta',
                'session_id' => 'sess-stream-multi',
            ],
        ];

        $binary = $this->fakeCli($lines);
        $this->useCliBinary($binary);

        $response = (new AssistantAgent)->stream('Hi', provider: 'claude-cli');

        $deltas = [];
        $streamEnd = null;

        foreach ($response as $event) {
            if ($event instanceof TextDelta) {
                $deltas[] = $event->delta;
            }

            if ($event instanceof StreamEnd) {
                $streamEnd = $event;
            }
        }

        expect(implode('', $deltas))->toBe('Alpha Beta')
            ->and($streamEnd)->not->toBeNull();
    });
});
