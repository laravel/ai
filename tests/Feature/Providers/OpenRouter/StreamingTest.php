<?php

namespace Tests\Feature\Providers\OpenRouter;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Tests\Feature\Tools\FixedNumberGenerator;
use Tests\TestCase;

use function Laravel\Ai\agent;

class StreamingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.openrouter' => [
            ...config('ai.providers.openrouter'),
            'key' => 'test-key',
        ]]);
    }

    public function test_streaming_emits_text_events(): void
    {
        Http::fake([
            '*' => Http::response($this->ssePayload([
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['content' => ' world'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2]],
            ])),
        ]);

        $events = [];
        foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
            $events[] = $event;
        }

        $types = array_map(fn ($e) => $e::class, $events);

        $this->assertContains(StreamStart::class, $types);
        $this->assertContains(TextStart::class, $types);
        $this->assertContains(TextDelta::class, $types);
        $this->assertContains(TextEnd::class, $types);
        $this->assertContains(StreamEnd::class, $types);

        $textDeltas = array_values(array_filter($events, fn ($e) => $e instanceof TextDelta));
        $this->assertSame('Hello', $textDeltas[0]->delta);
        $this->assertSame(' world', $textDeltas[1]->delta);
    }

    public function test_streaming_handles_tool_calls(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response($this->ssePayload([
                    ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_123', 'type' => 'function', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '']]]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{}']]]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 10]],
                ])),
                Http::response($this->ssePayload([
                    ['id' => 'chatcmpl-2', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'The number is 72019'], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-2', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5]],
                ])),
            ]),
        ]);

        $events = [];
        foreach (agent(tools: [new FixedNumberGenerator])->stream('Give me a number', provider: 'openrouter') as $event) {
            $events[] = $event;
        }

        $toolCallEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallEvent));
        $toolResultEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolResultEvent));

        $this->assertCount(1, $toolCallEvents);
        $this->assertSame('FixedNumberGenerator', $toolCallEvents[0]->toolCall->name);
        $this->assertCount(1, $toolResultEvents);
    }

    public function test_streaming_error_event_stops_stream(): void
    {
        Http::fake([
            '*' => Http::response($this->ssePayload([
                ['error' => ['code' => 'server_error', 'message' => 'Internal error']],
            ])),
        ]);

        $events = [];
        foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
            $events[] = $event;
        }

        $errorEvents = array_values(array_filter($events, fn ($e) => $e instanceof Error));
        $this->assertCount(1, $errorEvents);
        $this->assertSame('server_error', $errorEvents[0]->type);
    }

    public function test_streaming_error_finish_reason_emits_error_event(): void
    {
        Http::fake([
            '*' => Http::response($this->ssePayload([
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Partial'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'error', 'error' => ['code' => 502, 'message' => 'Provider returned error']]]],
            ])),
        ]);

        $events = [];
        foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
            $events[] = $event;
        }

        $errorEvents = array_values(array_filter($events, fn ($e) => $e instanceof Error));
        $this->assertCount(1, $errorEvents);
        $this->assertSame('502', $errorEvents[0]->type);
    }

    public function test_streaming_captures_usage_from_final_chunk(): void
    {
        Http::fake([
            '*' => Http::response($this->ssePayload([
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [], 'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 3]],
            ])),
        ]);

        $events = [];
        foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
            $events[] = $event;
        }

        $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd));
        $this->assertCount(1, $streamEnd);
        $this->assertSame(15, $streamEnd[0]->usage->promptTokens);
        $this->assertSame(3, $streamEnd[0]->usage->completionTokens);
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
