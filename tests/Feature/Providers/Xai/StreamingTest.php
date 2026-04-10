<?php

namespace Tests\Feature\Providers\Xai;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ProviderOptionsWithToolsAgent;

class StreamingTest extends XaiTestCase
{
    public function test_streaming_emits_text_events(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => 'response.output_text.delta', 'delta' => 'Hello'],
                    ['type' => 'response.output_text.delta', 'delta' => ' world'],
                    ['type' => 'response.output_text.done'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $this->assertInstanceOf(StreamStart::class, $events[0]);
        $this->assertInstanceOf(TextStart::class, $events[1]);
        $this->assertInstanceOf(TextDelta::class, $events[2]);
        $this->assertSame('Hello', $events[2]->delta);
        $this->assertInstanceOf(TextDelta::class, $events[3]);
        $this->assertSame(' world', $events[3]->delta);
        $this->assertInstanceOf(TextEnd::class, $events[4]);
        $this->assertInstanceOf(StreamEnd::class, $events[5]);
    }

    public function test_streaming_handles_tool_calls(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                        ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator']],
                        ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_1', 'delta' => '{}'],
                        ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_1', 'arguments' => '{}'],
                        ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        ['type' => 'response.created', 'response' => ['id' => 'resp_456', 'model' => 'grok-4-1-fast-reasoning']],
                        ['type' => 'response.output_text.delta', 'delta' => 'The number is 72019'],
                        ['type' => 'response.output_text.done'],
                        ['type' => 'response.completed', 'response' => ['id' => 'resp_456', 'usage' => ['input_tokens' => 20, 'output_tokens' => 10, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCallEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallEvent));
        $toolResultEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolResultEvent));

        $this->assertNotEmpty($toolCallEvents);
        $this->assertSame('FixedNumberGenerator', $toolCallEvents[0]->toolCall->name);
        $this->assertNotEmpty($toolResultEvents);
    }

    public function test_streaming_captures_usage(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => 'response.output_text.delta', 'delta' => 'Hi'],
                    ['type' => 'response.output_text.done'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 2], 'output_tokens_details' => ['reasoning_tokens' => 3]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

        $this->assertSame(8, $streamEnd->usage->promptTokens); // 10 - 2 cached
        $this->assertSame(5, $streamEnd->usage->completionTokens);
        $this->assertSame(2, $streamEnd->usage->cacheReadInputTokens);
        $this->assertSame(3, $streamEnd->usage->reasoningTokens);
    }

    public function test_streaming_error_event_stops_stream(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['type' => 'error', 'error' => ['code' => 'server_error', 'message' => 'Internal server error']],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(Error::class, $events[0]);
        $this->assertSame('server_error', $events[0]->type);
        $this->assertSame('Internal server error', $events[0]->message);
    }

    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream('Hello', provider: 'xai');

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

        $lines[] = 'data: [DONE]';

        return implode("\n\n", $lines)."\n\n";
    }
}
