<?php

namespace Tests\Feature\Providers\Anthropic;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ProviderOptionsWithToolsAgent;

class StreamingTest extends AnthropicTestCase
{
    public function test_streaming_emits_text_events(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => ' world']),
                    $this->contentBlockStop(0),
                    $this->messageDelta('end_turn', 10),
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
            'api.anthropic.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->messageStart(),
                        $this->contentBlockStart(0, ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => '']),
                        $this->contentBlockDelta(0, ['type' => 'input_json_delta', 'partial_json' => '{}']),
                        $this->contentBlockStop(0),
                        $this->messageDelta('tool_use', 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'The number is 72019']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 20, 'output_tokens' => 10],
                ]),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCallEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallEvent));

        $this->assertNotEmpty($toolCallEvents);
        $this->assertSame('FixedNumberGenerator', $toolCallEvents[0]->toolCall->name);
        $this->assertSame('toolu_1', $toolCallEvents[0]->toolCall->id);
    }

    public function test_streaming_handles_thinking_blocks(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'thinking', 'thinking' => '']),
                    $this->contentBlockDelta(0, ['type' => 'thinking_delta', 'thinking' => 'Let me think...']),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'Answer']),
                    $this->contentBlockStop(1),
                    $this->messageDelta('end_turn', 15),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $types = array_map(fn ($e) => $e::class, $events);

        $this->assertContains(ReasoningStart::class, $types);
        $this->assertContains(ReasoningDelta::class, $types);
        $this->assertContains(ReasoningEnd::class, $types);

        $reasoningDelta = array_values(array_filter($events, fn ($e) => $e instanceof ReasoningDelta))[0];
        $this->assertSame('Let me think...', $reasoningDelta->delta);
    }

    public function test_streaming_handles_server_tool_use(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search']),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'Result']),
                    $this->contentBlockStop(1),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $providerEvents = array_values(array_filter($events, fn ($e) => $e instanceof ProviderToolEvent));

        $this->assertCount(2, $providerEvents);
        $this->assertSame('started', $providerEvents[0]->status);
        $this->assertSame('completed', $providerEvents[1]->status);
        $this->assertSame('srvtoolu_1', $providerEvents[0]->itemId);
    }

    public function test_streaming_handles_provider_tool_results(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'search_results' => []]),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'Found it']),
                    $this->contentBlockStop(1),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $providerEvents = array_values(array_filter($events, fn ($e) => $e instanceof ProviderToolEvent));

        $this->assertNotEmpty($providerEvents);
        $this->assertSame('result_received', $providerEvents[0]->status);
        $this->assertSame('web_search_tool_result', $providerEvents[0]->type);
    }

    public function test_streaming_error_event_stops_stream(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Server overloaded']],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(Error::class, $events[0]);
        $this->assertSame('overloaded_error', $events[0]->type);
        $this->assertSame('Server overloaded', $events[0]->message);
    }

    /**
     * Collect all stream events from the agent's stream response.
     */
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
