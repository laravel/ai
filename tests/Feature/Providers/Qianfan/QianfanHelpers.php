<?php

namespace Tests\Feature\Providers\Qianfan;

use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

trait QianfanHelpers
{
    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream(
            'Hello',
            provider: 'qianfan',
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
            'id' => 'chatcmpl-qianfan-1',
            'object' => 'chat.completion.chunk',
            'model' => 'ernie-4.5-turbo-128k',
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
            'id' => 'chatcmpl-qianfan-1',
            'object' => 'chat.completion.chunk',
            'model' => 'ernie-4.5-turbo-128k',
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
            'id' => 'chatcmpl-qianfan-1',
            'object' => 'chat.completion.chunk',
            'model' => 'ernie-4.5-turbo-128k',
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
            'id' => 'chatcmpl-qianfan-1',
            'object' => 'chat.completion.chunk',
            'model' => 'ernie-4.5-turbo-128k',
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

    protected function fakeQianfanResponse(string $content = 'Hello', string $model = 'ernie-4.5-turbo-128k')
    {
        return Http::response([
            'id' => 'chatcmpl-qianfan-1',
            'object' => 'chat.completion',
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $content,
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ]);
    }

    protected function fakeQianfanToolCallResponse(): ResponseSequence
    {
        return Http::sequence([
            Http::response([
                'id' => 'chatcmpl-qianfan-tool-1',
                'object' => 'chat.completion',
                'model' => 'ernie-4.5-turbo-128k',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'id' => 'call_qianfan_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'FixedNumberGenerator',
                                'arguments' => '{}',
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => [
                    'prompt_tokens' => 12,
                    'completion_tokens' => 3,
                ],
            ]),
            $this->fakeQianfanResponse('The number is 72019'),
        ]);
    }
}
