<?php

namespace Tests\Feature\Providers\DeepSeek;

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

trait DeepSeekHelpers
{
    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream(
            'Hello',
            provider: 'deepseek',
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
            'model' => 'deepseek-chat',
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
            'model' => 'deepseek-chat',
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
            'model' => 'deepseek-chat',
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
            'model' => 'deepseek-chat',
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

    /**
     * Get the decoded messages array from a recorded request.
     */
    protected function requestMessages(int $requestIndex = 0): array
    {
        $recorded = Http::recorded();

        return json_decode($recorded[$requestIndex][0]->body(), true)['messages'] ?? [];
    }

    /**
     * Find the first message matching the given criteria.
     */
    protected function findMessage(array $messages, string $role, ?string $has = null): ?array
    {
        return collect($messages)->first(
            fn (array $m) => $m['role'] === $role && ($has === null || isset($m[$has]))
        );
    }

    /**
     * Filter messages by role.
     *
     * @return array<array>
     */
    protected function filterMessages(array $messages, string $role): array
    {
        return collect($messages)->filter(fn (array $m) => $m['role'] === $role)->values()->all();
    }
}
