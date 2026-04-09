<?php

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
use Tests\Feature\Providers\Anthropic\AnthropicHelpers;

uses(AnthropicHelpers::class);

test('streaming emits text events', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(
            body: anthropicSsePayload([
                messageStart(),
                contentBlockStart(0, ['type' => 'text', 'text' => '']),
                contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
                contentBlockDelta(0, ['type' => 'text_delta', 'text' => ' world']),
                contentBlockStop(0),
                messageDelta('end_turn', 10),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = anthropicCollectStreamEvents();

    expect($events[0])->toBeInstanceOf(StreamStart::class);
    expect($events[1])->toBeInstanceOf(TextStart::class);
    expect($events[2])->toBeInstanceOf(TextDelta::class);
    expect($events[2]->delta)->toBe('Hello');
    expect($events[3])->toBeInstanceOf(TextDelta::class);
    expect($events[3]->delta)->toBe(' world');
    expect($events[4])->toBeInstanceOf(TextEnd::class);
    expect($events[5])->toBeInstanceOf(StreamEnd::class);
});

test('streaming handles tool calls', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response(
                body: anthropicSsePayload([
                    messageStart(),
                    contentBlockStart(0, ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => '']),
                    contentBlockDelta(0, ['type' => 'input_json_delta', 'partial_json' => '{}']),
                    contentBlockStop(0),
                    messageDelta('tool_use', 5),
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

    $events = anthropicCollectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

    $toolCallEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallEvent));

    expect($toolCallEvents)->not->toBeEmpty();
    expect($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator');
    expect($toolCallEvents[0]->toolCall->id)->toBe('toolu_1');
});

test('streaming handles thinking blocks', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(
            body: anthropicSsePayload([
                messageStart(),
                contentBlockStart(0, ['type' => 'thinking', 'thinking' => '']),
                contentBlockDelta(0, ['type' => 'thinking_delta', 'thinking' => 'Let me think...']),
                contentBlockStop(0),
                contentBlockStart(1, ['type' => 'text', 'text' => '']),
                contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'Answer']),
                contentBlockStop(1),
                messageDelta('end_turn', 15),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = anthropicCollectStreamEvents();

    $types = array_map(fn ($e) => $e::class, $events);

    expect($types)->toContain(ReasoningStart::class);
    expect($types)->toContain(ReasoningDelta::class);
    expect($types)->toContain(ReasoningEnd::class);

    $reasoningDelta = array_values(array_filter($events, fn ($e) => $e instanceof ReasoningDelta))[0];
    expect($reasoningDelta->delta)->toBe('Let me think...');
});

test('streaming handles server tool use', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(
            body: anthropicSsePayload([
                messageStart(),
                contentBlockStart(0, ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search']),
                contentBlockStop(0),
                contentBlockStart(1, ['type' => 'text', 'text' => '']),
                contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'Result']),
                contentBlockStop(1),
                messageDelta('end_turn', 10),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = anthropicCollectStreamEvents();

    $providerEvents = array_values(array_filter($events, fn ($e) => $e instanceof ProviderToolEvent));

    expect($providerEvents)->toHaveCount(2);
    expect($providerEvents[0]->status)->toBe('started');
    expect($providerEvents[1]->status)->toBe('completed');
    expect($providerEvents[0]->itemId)->toBe('srvtoolu_1');
});

test('streaming handles provider tool results', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(
            body: anthropicSsePayload([
                messageStart(),
                contentBlockStart(0, ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'search_results' => []]),
                contentBlockStop(0),
                contentBlockStart(1, ['type' => 'text', 'text' => '']),
                contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'Found it']),
                contentBlockStop(1),
                messageDelta('end_turn', 10),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = anthropicCollectStreamEvents();

    $providerEvents = array_values(array_filter($events, fn ($e) => $e instanceof ProviderToolEvent));

    expect($providerEvents)->not->toBeEmpty();
    expect($providerEvents[0]->status)->toBe('result_received');
    expect($providerEvents[0]->type)->toBe('web_search_tool_result');
});

test('streaming error event stops stream', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(
            body: anthropicSsePayload([
                ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Server overloaded']],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = anthropicCollectStreamEvents();

    expect($events)->toHaveCount(1);
    expect($events[0])->toBeInstanceOf(Error::class);
    expect($events[0]->type)->toBe('overloaded_error');
    expect($events[0]->message)->toBe('Server overloaded');
});

/**
 * Collect all stream events from the agent's stream response.
 */
function anthropicCollectStreamEvents(?object $agent = null): array
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

function anthropicSsePayload(array $events): string
{
    $lines = [];

    foreach ($events as $event) {
        $lines[] = 'data: '.json_encode($event);
    }

    return implode("\n\n", $lines)."\n\n";
}

function messageStart(): array
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

function contentBlockStart(int $index, array $contentBlock): array
{
    return [
        'type' => 'content_block_start',
        'index' => $index,
        'content_block' => $contentBlock,
    ];
}

function contentBlockDelta(int $index, array $delta): array
{
    return [
        'type' => 'content_block_delta',
        'index' => $index,
        'delta' => $delta,
    ];
}

function contentBlockStop(int $index): array
{
    return [
        'type' => 'content_block_stop',
        'index' => $index,
    ];
}

test('streaming captures input tokens from message start', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(
            body: anthropicSsePayload([
                [
                    'type' => 'message_start',
                    'message' => [
                        'id' => 'msg_1',
                        'model' => 'claude-sonnet-4-6',
                        'role' => 'assistant',
                        'content' => [],
                        'usage' => [
                            'input_tokens' => 42,
                            'output_tokens' => 0,
                            'cache_creation_input_tokens' => 100,
                            'cache_read_input_tokens' => 50,
                        ],
                    ],
                ],
                contentBlockStart(0, ['type' => 'text', 'text' => '']),
                contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
                contentBlockStop(0),
                messageDelta('end_turn', 10),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = anthropicCollectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->promptTokens)->toBe(42);
    expect($streamEnd->usage->completionTokens)->toBe(10);
    expect($streamEnd->usage->cacheWriteInputTokens)->toBe(100);
    expect($streamEnd->usage->cacheReadInputTokens)->toBe(50);
});

function messageDelta(string $stopReason, int $outputTokens): array
{
    return [
        'type' => 'message_delta',
        'delta' => ['stop_reason' => $stopReason],
        'usage' => ['output_tokens' => $outputTokens],
    ];
}
