<?php

namespace Tests\Feature\Providers\Mistral;

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

use function Laravel\Ai\agent;

class StreamingTest extends MistralTestCase
{
    public function test_streaming_emits_text_events(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['content' => ' world'], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
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
                        ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '']]]], 'finish_reason' => null]]],
                        ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{}']]]], 'finish_reason' => null]]],
                        ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        ['id' => 'chatcmpl-456', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'The number is 72019'], 'finish_reason' => null]]],
                        ['id' => 'chatcmpl-456', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10]],
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
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

        $this->assertSame(10, $streamEnd->usage->promptTokens);
        $this->assertSame(5, $streamEnd->usage->completionTokens);
    }

    public function test_streaming_error_event_stops_stream(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['error' => ['code' => 'server_error', 'message' => 'Internal server error']],
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

    /**
     * Collect all stream events from the agent's stream response.
     */
    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream('Hello', provider: 'mistral');

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
