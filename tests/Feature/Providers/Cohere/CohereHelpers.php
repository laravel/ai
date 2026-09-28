<?php

namespace Tests\Feature\Providers\Cohere;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

trait CohereHelpers
{
    protected function fakeTextResponse(string|array $text = 'Hello', string $finishReason = 'COMPLETE'): PromiseInterface
    {
        return Http::response([
            'id' => '08067b1d-d35b-427c-a287-d913f35bd6ca',
            'message' => [
                'role' => 'assistant',
                'content' => is_array($text) ? $text : [['type' => 'text', 'text' => $text]],
            ],
            'finish_reason' => $finishReason,
            'usage' => [
                'billed_units' => ['input_tokens' => 20, 'output_tokens' => 5],
                'tokens' => ['input_tokens' => 557, 'output_tokens' => 8],
                'cached_tokens' => 480,
            ],
        ]);
    }

    protected function fakeToolCallResponse(string $toolName = 'FixedNumberGenerator', string $callId = 'call_1', string $arguments = '{}'): PromiseInterface
    {
        return Http::response([
            'id' => 'e8ffabdc-7ab2-4b05-bf50-fdae51da355a',
            'message' => [
                'role' => 'assistant',
                'tool_plan' => 'I will use the tool.',
                'tool_calls' => [[
                    'id' => $callId,
                    'type' => 'function',
                    'function' => ['name' => $toolName, 'arguments' => $arguments],
                ]],
            ],
            'finish_reason' => 'TOOL_CALL',
            'usage' => [
                'billed_units' => ['input_tokens' => 38, 'output_tokens' => 17],
                'tokens' => ['input_tokens' => 1439, 'output_tokens' => 47],
            ],
        ]);
    }

    protected function fakeStreamResponse(array $events): PromiseInterface
    {
        return Http::response(
            body: $this->ssePayload($events),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        );
    }

    protected function streamTextEvents(string ...$deltas): array
    {
        return [
            ['type' => 'message-start', 'id' => 'msg_1', 'delta' => ['message' => ['role' => 'assistant', 'content' => [], 'tool_plan' => '', 'tool_calls' => [], 'citations' => []]]],
            ['type' => 'content-start', 'index' => 0, 'delta' => ['message' => ['content' => ['type' => 'text', 'text' => '']]]],
            ...array_map(fn (string $delta): array => ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['text' => $delta]]]], $deltas),
            ['type' => 'content-end', 'index' => 0],
            ['type' => 'message-end', 'delta' => ['finish_reason' => 'COMPLETE', 'usage' => ['tokens' => ['input_tokens' => 20, 'output_tokens' => 10]]]],
        ];
    }

    protected function streamToolCallEvents(string $toolName = 'FixedNumberGenerator', string $callId = 'call_1', array $argumentChunks = ['{}']): array
    {
        return [
            ['type' => 'message-start', 'id' => 'msg_1', 'delta' => ['message' => ['role' => 'assistant', 'content' => [], 'tool_plan' => '', 'tool_calls' => [], 'citations' => []]]],
            ['type' => 'tool-plan-delta', 'delta' => ['message' => ['tool_plan' => 'I will use the tool.']]],
            ['type' => 'tool-call-start', 'index' => 0, 'delta' => ['message' => ['tool_calls' => ['id' => $callId, 'type' => 'function', 'function' => ['name' => $toolName, 'arguments' => '']]]]],
            ...array_map(fn (string $chunk): array => ['type' => 'tool-call-delta', 'index' => 0, 'delta' => ['message' => ['tool_calls' => ['function' => ['arguments' => $chunk]]]]], $argumentChunks),
            ['type' => 'tool-call-end', 'index' => 0],
            ['type' => 'message-end', 'delta' => ['finish_reason' => 'TOOL_CALL', 'usage' => ['tokens' => ['input_tokens' => 10, 'output_tokens' => 5]]]],
        ];
    }

    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $events = [];

        foreach ($agent->stream('Hello', provider: 'cohere') as $event) {
            $events[] = $event;
        }

        return $events;
    }

    protected function ssePayload(array $events): string
    {
        $lines = [];

        foreach ($events as $event) {
            $lines[] = 'event: '.$event['type']."\ndata: ".json_encode($event);
        }

        $lines[] = 'data: [DONE]';

        return implode("\n\n", $lines)."\n\n";
    }
}
