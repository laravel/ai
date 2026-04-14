<?php

namespace Tests\Feature\Providers\WorkersAi;

use Tests\Fixtures\Agents\AssistantAgent;

trait WorkersAiHelpers
{
    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream(
            'Hello',
            provider: 'workersai',
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
            if ($event === '[DONE]') {
                $lines[] = 'data: [DONE]';
            } else {
                $lines[] = 'data: '.json_encode($event);
            }
        }

        return implode("\n\n", $lines)."\n\n";
    }

    protected function chatChunk(array $delta, ?string $finishReason = null): array
    {
        return [
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion.chunk',
            'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'choices' => [[
                'index' => 0,
                'delta' => $delta,
                'finish_reason' => $finishReason,
            ]],
        ];
    }

    protected function chatChunkFinish(string $finishReason, ?array $usage = null): array
    {
        $chunk = [
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion.chunk',
            'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'choices' => [[
                'index' => 0,
                'delta' => (object) [],
                'finish_reason' => $finishReason,
            ]],
        ];

        if ($usage) {
            $chunk['usage'] = $usage;
        }

        return $chunk;
    }

    protected function chatChunkToolCallStart(int $index, string $id, string $name): array
    {
        return [
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion.chunk',
            'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'choices' => [[
                'index' => 0,
                'delta' => [
                    'tool_calls' => [[
                        'index' => $index,
                        'id' => $id,
                        'type' => 'function',
                        'function' => ['name' => $name, 'arguments' => ''],
                    ]],
                ],
                'finish_reason' => null,
            ]],
        ];
    }

    protected function chatChunkToolCallDelta(int $index, string $arguments): array
    {
        return [
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion.chunk',
            'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'choices' => [[
                'index' => 0,
                'delta' => [
                    'tool_calls' => [[
                        'index' => $index,
                        'function' => ['arguments' => $arguments],
                    ]],
                ],
                'finish_reason' => null,
            ]],
        ];
    }

    protected function chatChunkReasoning(string $reasoningContent): array
    {
        return [
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion.chunk',
            'model' => '@cf/moonshotai/kimi-k2.5',
            'choices' => [[
                'index' => 0,
                'delta' => [
                    'reasoning_content' => $reasoningContent,
                ],
                'finish_reason' => null,
            ]],
        ];
    }
}
