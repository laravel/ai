<?php

namespace Tests\Feature\Providers\Ollama;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\AssistantAgent;

trait OllamaHelpers
{
    protected function fakeTextResponse(string $text = 'Hello'): PromiseInterface
    {
        return Http::response([
            'model' => 'llama3.1:8b',
            'message' => [
                'role' => 'assistant',
                'content' => $text,
            ],
            'done_reason' => 'stop',
            'done' => true,
            'prompt_eval_count' => 1,
            'eval_count' => 1,
        ]);
    }

    protected function fakeToolCallResponse(string $toolName = 'FixedNumberGenerator', ?string $callId = null): PromiseInterface
    {
        return Http::response([
            'model' => 'llama3.1:8b',
            'message' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'id' => $callId ?? 'call_123',
                    'function' => [
                        'name' => $toolName,
                        'arguments' => (object) [],
                    ],
                ]],
            ],
            'done_reason' => 'tool_calls',
            'done' => true,
            'prompt_eval_count' => 10,
            'eval_count' => 5,
        ]);
    }

    protected function fakeStructuredResponse(string $json = '{"symbol": "Au"}'): PromiseInterface
    {
        return Http::response([
            'model' => 'llama3.1:8b',
            'message' => [
                'role' => 'assistant',
                'content' => $json,
            ],
            'done_reason' => 'stop',
            'done' => true,
            'prompt_eval_count' => 10,
            'eval_count' => 5,
        ]);
    }

    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream(
            'Hello',
            provider: 'ollama',
        );

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        return $events;
    }

    protected function ndjsonPayload(array $chunks): string
    {
        return implode("\n", array_map('json_encode', $chunks))."\n";
    }

    protected function chatChunk(string $content, bool $done = false, ?string $doneReason = null, ?array $usage = null): array
    {
        $chunk = [
            'model' => 'llama3.1:8b',
            'message' => ['role' => 'assistant', 'content' => $content],
            'done' => $done,
        ];

        if ($done && $doneReason) {
            $chunk['done_reason'] = $doneReason;
        }

        if ($usage) {
            $chunk['prompt_eval_count'] = $usage['prompt_eval_count'] ?? 0;
            $chunk['eval_count'] = $usage['eval_count'] ?? 0;
        }

        return $chunk;
    }

    protected function chatChunkWithToolCalls(array $toolCalls, bool $done = true): array
    {
        return [
            'model' => 'llama3.1:8b',
            'message' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => $toolCalls,
            ],
            'done_reason' => 'tool_calls',
            'done' => $done,
            'prompt_eval_count' => 10,
            'eval_count' => 5,
        ];
    }

    protected function toolCallChunk(string $id, string $name, array $arguments = []): array
    {
        return [
            'id' => $id,
            'function' => [
                'name' => $name,
                'arguments' => (object) $arguments,
            ],
        ];
    }
}
