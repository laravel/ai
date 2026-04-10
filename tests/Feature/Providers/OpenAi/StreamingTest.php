<?php

namespace Tests\Feature\Providers\OpenAi;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\Error;
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
use Tests\TestCase;

class StreamingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.openai' => [
            ...config('ai.providers.openai'),
            'key' => 'test-key',
        ]]);
    }

    public function test_streaming_emits_text_events(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputTextDelta('Hello'),
                    $this->outputTextDelta(' world'),
                    $this->outputTextDone('Hello world'),
                    $this->responseCompleted(10, 5),
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
        $this->assertInstanceOf(StreamEnd::class, $events[count($events) - 1]);
    }

    public function test_streaming_handles_tool_calls(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->responseCreated(),
                        $this->outputItemAdded('fc_1', 'call_1', 'FixedNumberGenerator'),
                        $this->functionCallArgumentsDelta('fc_1', '{}'),
                        $this->functionCallArgumentsDone('fc_1', '{}'),
                        $this->responseCompleted(10, 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        [
                            'type' => 'response.created',
                            'response' => ['id' => 'resp_2', 'model' => 'gpt-5.4', 'status' => 'in_progress', 'output' => []],
                        ],
                        $this->outputTextDelta('The number is 72019'),
                        $this->outputTextDone('The number is 72019'),
                        $this->responseCompleted(20, 10),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCallEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallEvent));

        $this->assertNotEmpty($toolCallEvents);
        $this->assertSame('FixedNumberGenerator', $toolCallEvents[0]->toolCall->name);
        $this->assertSame('call_1', $toolCallEvents[0]->toolCall->resultId);
    }

    public function test_streaming_handles_reasoning_events(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'Let me think...', 'item_id' => 'rs_1'],
                    [
                        'type' => 'response.output_item.done',
                        'item' => ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'Let me think...']]],
                    ],
                    $this->outputTextDelta('Answer'),
                    $this->outputTextDone('Answer'),
                    $this->responseCompleted(10, 15),
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

    public function test_streaming_error_event_stops_stream(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    ['type' => 'error', 'error' => ['code' => 'server_error', 'message' => 'Server overloaded']],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(Error::class, $events[0]);
        $this->assertSame('server_error', $events[0]->type);
        $this->assertSame('Server overloaded', $events[0]->message);
    }

    public function test_streaming_captures_usage_from_response_completed(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputTextDelta('Hello'),
                    $this->outputTextDone('Hello'),
                    $this->responseCompleted(42, 10, cachedTokens: 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

        $this->assertSame(37, $streamEnd->usage->promptTokens);
        $this->assertSame(10, $streamEnd->usage->completionTokens);
        $this->assertSame(5, $streamEnd->usage->cacheReadInputTokens);
    }

    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream(
            'Hello',
            provider: 'openai',
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

    protected function responseCreated(): array
    {
        return [
            'type' => 'response.created',
            'response' => [
                'id' => 'resp_1',
                'model' => 'gpt-5.4',
                'status' => 'in_progress',
                'output' => [],
            ],
        ];
    }

    protected function outputTextDelta(string $text): array
    {
        return [
            'type' => 'response.output_text.delta',
            'delta' => $text,
            'item_id' => 'msg_1',
            'output_index' => 0,
            'content_index' => 0,
        ];
    }

    protected function outputTextDone(string $text): array
    {
        return [
            'type' => 'response.output_text.done',
            'text' => $text,
            'item_id' => 'msg_1',
            'output_index' => 0,
            'content_index' => 0,
        ];
    }

    protected function outputItemAdded(string $id, string $callId, string $name): array
    {
        return [
            'type' => 'response.output_item.added',
            'output_index' => 0,
            'item' => [
                'type' => 'function_call',
                'id' => $id,
                'call_id' => $callId,
                'name' => $name,
                'arguments' => '',
                'status' => 'in_progress',
            ],
        ];
    }

    protected function functionCallArgumentsDelta(string $itemId, string $delta): array
    {
        return [
            'type' => 'response.function_call_arguments.delta',
            'item_id' => $itemId,
            'delta' => $delta,
            'output_index' => 0,
        ];
    }

    protected function functionCallArgumentsDone(string $itemId, string $arguments): array
    {
        return [
            'type' => 'response.function_call_arguments.done',
            'item_id' => $itemId,
            'arguments' => $arguments,
            'output_index' => 0,
        ];
    }

    protected function responseCompleted(int $inputTokens, int $outputTokens, int $cachedTokens = 0, int $reasoningTokens = 0): array
    {
        return [
            'type' => 'response.completed',
            'response' => [
                'id' => 'resp_1',
                'model' => 'gpt-5.4',
                'status' => 'completed',
                'output' => [],
                'usage' => [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'input_tokens_details' => [
                        'cached_tokens' => $cachedTokens,
                    ],
                    'output_tokens_details' => [
                        'reasoning_tokens' => $reasoningTokens,
                    ],
                ],
            ],
        ];
    }
}
