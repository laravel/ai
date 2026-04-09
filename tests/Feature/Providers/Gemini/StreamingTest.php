<?php

namespace Tests\Feature\Providers\Gemini;

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

class StreamingTest extends GeminiTestCase
{
    public function test_streaming_emits_text_events(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->geminiChunk([['text' => 'Hello']]),
                    $this->geminiChunk([['text' => ' world']]),
                    $this->geminiChunkWithUsage([['text' => '']], 10, 5),
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
            'generativelanguage.googleapis.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->geminiChunkWithUsage([[
                            'functionCall' => [
                                'id' => 'call_1',
                                'name' => 'FixedNumberGenerator',
                                'args' => (object) [],
                            ],
                        ]], 10, 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        $this->geminiChunkWithUsage([['text' => 'The number is 72019']], 20, 10),
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
    }

    public function test_streaming_handles_thinking_parts(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->geminiChunk([['text' => 'Let me think...', 'thought' => true]]),
                    $this->geminiChunk([['text' => 'Answer']]),
                    $this->geminiChunkWithUsage([], 10, 5),
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
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([
                    ['error' => ['code' => 'overloaded', 'message' => 'Server overloaded']],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(Error::class, $events[0]);
        $this->assertSame('overloaded', $events[0]->type);
        $this->assertSame('Server overloaded', $events[0]->message);
    }

    public function test_streaming_captures_usage_from_final_chunk(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->geminiChunk([['text' => 'Hello']]),
                    $this->geminiChunkWithUsage([['text' => '']], 42, 10, cachedTokens: 5),
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

    public function test_streaming_thinking_parts_are_excluded_from_tool_call_continuation(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->geminiChunk([
                            ['text' => 'thinking...', 'thought' => true],
                            ['functionCall' => ['id' => 'call_1', 'name' => 'FixedNumberGenerator', 'args' => (object) []]],
                        ]),
                        $this->geminiChunkWithUsage([], 10, 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        $this->geminiChunkWithUsage([['text' => 'Done']], 20, 10),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);

        $followUpContents = $recorded[1][0]->data()['contents'];

        foreach ($followUpContents as $content) {
            if ($content['role'] === 'model') {
                foreach ($content['parts'] as $part) {
                    $this->assertFalse(
                        $part['thought'] ?? false,
                        'Streaming: thinking parts should be excluded from tool call continuation'
                    );
                }
            }
        }
    }

    public function test_streaming_uses_sse_endpoint_with_alt_parameter(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->geminiChunkWithUsage([['text' => 'Hello']], 10, 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $this->collectStreamEvents();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'streamGenerateContent?alt=sse');
        });
    }

    /**
     * Collect all stream events from the agent's stream response.
     */
    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream(
            'Hello',
            provider: 'gemini',
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

    protected function geminiChunk(array $parts, ?string $modelVersion = null): array
    {
        return [
            'candidates' => [[
                'content' => [
                    'parts' => $parts,
                    'role' => 'model',
                ],
            ]],
            'modelVersion' => $modelVersion ?? 'gemini-3-flash-preview',
        ];
    }

    protected function geminiChunkWithUsage(array $parts, int $promptTokens, int $candidatesTokens, int $cachedTokens = 0, ?string $modelVersion = null): array
    {
        $chunk = $this->geminiChunk($parts, $modelVersion);

        $chunk['usageMetadata'] = array_filter([
            'promptTokenCount' => $promptTokens,
            'candidatesTokenCount' => $candidatesTokens,
            'totalTokenCount' => $promptTokens + $candidatesTokens,
            'cachedContentTokenCount' => $cachedTokens ?: null,
        ]);

        return $chunk;
    }
}
