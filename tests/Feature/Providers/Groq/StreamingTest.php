<?php

namespace Tests\Feature\Providers\Groq;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\Error;
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

        config(['ai.providers.groq' => [
            ...config('ai.providers.groq'),
            'key' => 'test-key',
        ]]);
    }

    public function test_streaming_emits_text_events(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'content' => 'Hello']),
                    $this->chatChunk(['content' => ' world']),
                    $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                    '[DONE]',
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
        $this->assertInstanceOf(TextEnd::class, $events[count($events) - 2]);
        $this->assertInstanceOf(StreamEnd::class, $events[count($events) - 1]);
    }

    public function test_streaming_handles_tool_calls(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->chatChunkToolCallStart(0, 'call_1', 'FixedNumberGenerator'),
                        $this->chatChunkToolCallDelta(0, '{}'),
                        $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                        '[DONE]',
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                        $this->chatChunkFinish('stop', ['prompt_tokens' => 20, 'completion_tokens' => 10]),
                        '[DONE]',
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
        $this->assertSame('call_1', $toolCallEvents[0]->toolCall->id);
    }

    public function test_streaming_error_event_stops_stream(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(
                body: $this->ssePayload([
                    ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Rate limit exceeded']],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(Error::class, $events[0]);
        $this->assertSame('rate_limit_exceeded', $events[0]->type);
        $this->assertSame('Rate limit exceeded', $events[0]->message);
    }

    public function test_streaming_captures_usage_from_final_chunk(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'content' => 'Hello']),
                    $this->chatChunkFinish('stop', ['prompt_tokens' => 42, 'completion_tokens' => 10]),
                    '[DONE]',
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

        $this->assertSame(42, $streamEnd->usage->promptTokens);
        $this->assertSame(10, $streamEnd->usage->completionTokens);
    }

    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream(
            'Hello',
            provider: 'groq',
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
            'model' => 'openai/gpt-oss-20b',
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
            'model' => 'openai/gpt-oss-20b',
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
            'model' => 'openai/gpt-oss-20b',
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
            'model' => 'openai/gpt-oss-20b',
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
}
