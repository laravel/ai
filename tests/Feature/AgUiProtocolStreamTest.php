<?php

use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\Data;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Symfony\Component\HttpFoundation\Response;

/**
 * @return array{response: Response, events: array<int, array<string, mixed>>}
 */
function agUiProtocolResponse(array $events, ?string $threadId = null, ?string $runId = null, ?string $conversationId = null): array
{
    $stream = (new StreamableAgentResponse('invocation-1', fn () => yield from $events, new Data\Meta('anthropic', 'claude-sonnet-4-6')))
        ->withinConversation($conversationId)
        ->usingAgUiProtocol(threadId: $threadId, runId: $runId);

    $response = $stream->toResponse(request());
    $output = '';

    ob_start(function (string $buffer) use (&$output): string {
        $output .= $buffer;

        return '';
    });

    $response->sendContent();

    ob_end_clean();

    $decoded = collect(explode("\n\n", trim($output)))
        ->map(fn (string $frame): string => str_replace('data: ', '', $frame))
        ->map(fn (string $payload): array => json_decode($payload, true, flags: JSON_THROW_ON_ERROR))
        ->all();

    return ['response' => $response, 'events' => $decoded];
}

test('it streams text reasoning and tools using the ag ui event protocol', function () {
    $result = agUiProtocolResponse([
        new StreamStart('stream-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextStart('event-1', 'message-1', time()),
        new TextDelta('event-2', 'message-1', 'I will check.', time()),
        new TextEnd('event-3', 'message-1', time()),
        new ReasoningStart('event-4', 'reasoning-1', time()),
        new ReasoningDelta('event-5', 'reasoning-1', 'Looking it up.', time()),
        new ReasoningEnd('event-6', 'reasoning-1', time()),
        new ToolCall('event-7', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new ToolResult('result-message-1', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], ['condition' => 'sunny']), true, null, time()),
        new StreamEnd('event-8', 'stop', new Usage, time()),
    ], threadId: 'thread-1', runId: 'run-1');

    expect($result['events'])->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'message-1', 'role' => 'assistant'],
        ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'message-1', 'delta' => 'I will check.'],
        ['type' => 'TEXT_MESSAGE_END', 'messageId' => 'message-1'],
        ['type' => 'REASONING_START', 'messageId' => 'reasoning-1'],
        ['type' => 'REASONING_MESSAGE_START', 'messageId' => 'reasoning-1', 'role' => 'reasoning'],
        ['type' => 'REASONING_MESSAGE_CONTENT', 'messageId' => 'reasoning-1', 'delta' => 'Looking it up.'],
        ['type' => 'REASONING_MESSAGE_END', 'messageId' => 'reasoning-1'],
        ['type' => 'REASONING_END', 'messageId' => 'reasoning-1'],
        ['type' => 'TOOL_CALL_START', 'toolCallId' => 'call-1', 'toolCallName' => 'GetWeather', 'parentMessageId' => 'message-1'],
        ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'call-1', 'delta' => '{"city":"Lisbon"}'],
        ['type' => 'TOOL_CALL_END', 'toolCallId' => 'call-1'],
        ['type' => 'TOOL_CALL_RESULT', 'messageId' => 'result-message-1', 'toolCallId' => 'call-1', 'content' => '{"condition":"sunny"}', 'role' => 'tool'],
        ['type' => 'RUN_FINISHED', 'threadId' => 'thread-1', 'runId' => 'run-1', 'outcome' => ['type' => 'success']],
    ])->and($result['response']->headers->get('Content-Type'))->toContain('text/event-stream')
        ->and($result['response']->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($result['events'])->not->toContain('[DONE]');
});

test('it synthesizes required run and message lifecycle events', function () {
    $result = agUiProtocolResponse([
        new TextDelta('event-1', 'message-1', 'Hello', time()),
    ], conversationId: 'conversation-1');

    expect($result['events'])->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'conversation-1', 'runId' => 'invocation-1'],
        ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'message-1', 'role' => 'assistant'],
        ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'message-1', 'delta' => 'Hello'],
        ['type' => 'TEXT_MESSAGE_END', 'messageId' => 'message-1'],
        ['type' => 'RUN_FINISHED', 'threadId' => 'conversation-1', 'runId' => 'invocation-1', 'outcome' => ['type' => 'success']],
    ]);
});

test('a stream error terminates the ag ui run with run error', function () {
    $result = agUiProtocolResponse([
        new TextDelta('event-1', 'message-1', 'Partial', time()),
        new Error('event-2', 'server_error', 'Server overloaded', false, time()),
        new StreamEnd('event-3', 'error', new Usage, time()),
    ], threadId: 'thread-1', runId: 'run-1');

    expect($result['events'])->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'message-1', 'role' => 'assistant'],
        ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'message-1', 'delta' => 'Partial'],
        ['type' => 'TEXT_MESSAGE_END', 'messageId' => 'message-1'],
        ['type' => 'RUN_ERROR', 'message' => 'Server overloaded', 'code' => 'server_error'],
    ]);
});

test('a resumed run emits a result for a tool call from the interrupted run', function () {
    $result = agUiProtocolResponse([
        new ToolResult('result-message-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new TextDelta('event-1', 'message-1', 'Done.', time()),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ], threadId: 'thread-1', runId: 'run-2');

    expect($result['events'])->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-2'],
        ['type' => 'TOOL_CALL_START', 'toolCallId' => 'call-1', 'toolCallName' => 'DeleteFile'],
        ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'call-1', 'delta' => '{"path":"a.txt"}'],
        ['type' => 'TOOL_CALL_END', 'toolCallId' => 'call-1'],
        ['type' => 'TOOL_CALL_RESULT', 'messageId' => 'result-message-1', 'toolCallId' => 'call-1', 'content' => 'deleted', 'role' => 'tool'],
        ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'message-1', 'role' => 'assistant'],
        ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'message-1', 'delta' => 'Done.'],
        ['type' => 'TEXT_MESSAGE_END', 'messageId' => 'message-1'],
        ['type' => 'RUN_FINISHED', 'threadId' => 'thread-1', 'runId' => 'run-2', 'outcome' => ['type' => 'success']],
    ]);
});

test('reasoning interleaved within a text message does not reuse a message id', function () {
    $result = agUiProtocolResponse([
        new TextDelta('event-1', 'message-1', 'Before', time()),
        new ReasoningDelta('event-2', 'reasoning-1', 'Thinking.', time()),
        new TextDelta('event-3', 'message-1', 'After', time()),
        new TextEnd('event-4', 'message-1', time()),
        new StreamEnd('event-5', 'stop', new Usage, time()),
    ], threadId: 'thread-1', runId: 'run-1');

    $starts = collect($result['events'])
        ->where('type', 'TEXT_MESSAGE_START')
        ->pluck('messageId');

    expect($result['events'])->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'message-1', 'role' => 'assistant'],
        ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'message-1', 'delta' => 'Before'],
        ['type' => 'TEXT_MESSAGE_END', 'messageId' => 'message-1'],
        ['type' => 'REASONING_START', 'messageId' => 'reasoning-1'],
        ['type' => 'REASONING_MESSAGE_START', 'messageId' => 'reasoning-1', 'role' => 'reasoning'],
        ['type' => 'REASONING_MESSAGE_CONTENT', 'messageId' => 'reasoning-1', 'delta' => 'Thinking.'],
        ['type' => 'REASONING_MESSAGE_END', 'messageId' => 'reasoning-1'],
        ['type' => 'REASONING_END', 'messageId' => 'reasoning-1'],
        ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'message-1#1', 'role' => 'assistant'],
        ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'message-1#1', 'delta' => 'After'],
        ['type' => 'TEXT_MESSAGE_END', 'messageId' => 'message-1#1'],
        ['type' => 'RUN_FINISHED', 'threadId' => 'thread-1', 'runId' => 'run-1', 'outcome' => ['type' => 'success']],
    ])->and($starts->duplicates())->toBeEmpty();
});

test('a recoverable error is emitted without terminating the run', function () {
    $result = agUiProtocolResponse([
        new TextDelta('event-1', 'message-1', 'Partial', time()),
        new Error('event-2', 'rate_limit', 'Slow down', true, time()),
        new TextDelta('event-3', 'message-1', 'Resumed', time()),
        new StreamEnd('event-4', 'stop', new Usage, time()),
    ], threadId: 'thread-1', runId: 'run-1');

    $types = collect($result['events'])->pluck('type');

    expect($types->all())->toBe([
        'RUN_STARTED',
        'TEXT_MESSAGE_START',
        'TEXT_MESSAGE_CONTENT',
        'CUSTOM',
        'TEXT_MESSAGE_CONTENT',
        'TEXT_MESSAGE_END',
        'RUN_FINISHED',
    ])->and($types)->not->toContain('RUN_ERROR')
        ->and($result['events'][3]['name'])->toBe('error');
});

test('a content-less text block emits no empty message frames', function () {
    $result = agUiProtocolResponse([
        new TextStart('event-1', 'message-1', time()),
        new TextEnd('event-2', 'message-1', time()),
        new ToolCall('event-3', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new StreamEnd('event-4', 'stop', new Usage, time()),
    ], threadId: 'thread-1', runId: 'run-1');

    expect(collect($result['events'])->pluck('type')->all())->toBe([
        'RUN_STARTED',
        'TOOL_CALL_START',
        'TOOL_CALL_ARGS',
        'TOOL_CALL_END',
        'RUN_FINISHED',
    ]);
});

test('an exception thrown mid stream terminates the run cleanly and reports the exception', function () {
    Exceptions::fake();

    $events = (function () {
        yield new TextDelta('event-1', 'message-1', 'Partial', time());

        throw new RuntimeException('Stream exploded');
    })();

    $stream = (new StreamableAgentResponse('invocation-1', fn () => yield from $events, new Data\Meta('anthropic', 'claude-sonnet-4-6')))
        ->usingAgUiProtocol(threadId: 'thread-1', runId: 'run-1');

    $response = $stream->toResponse(request());

    $output = '';
    ob_start(function (string $buffer) use (&$output): string {
        $output .= $buffer;

        return '';
    });

    // The exception must not escape the send callback, or the framework would append error output after the SSE terminal.
    $response->sendContent();

    ob_end_clean();

    $decoded = collect(explode("\n\n", trim($output)))
        ->filter()
        ->map(fn (string $frame): array => json_decode(str_replace('data: ', '', $frame), true, flags: JSON_THROW_ON_ERROR));

    expect($decoded->last())->toBe([
        'type' => 'RUN_ERROR',
        'message' => 'Stream exploded',
        'code' => 'error',
    ])->and($decoded->pluck('type'))->toContain('TEXT_MESSAGE_END');

    Exceptions::assertReported(RuntimeException::class);
});

test('tool approvals finish the ag ui run with interrupt outcomes', function () {
    $result = agUiProtocolResponse([
        new ToolCall('event-1', new Data\ToolCall('call-1', 'DeleteFile', ['path' => 'a.txt']), time()),
        new ToolApprovalRequest('event-2', collect([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'a.txt'], 'Deleting files requires confirmation.'),
        ]), time()),
        new StreamEnd('event-3', 'tool_calls', new Usage, time()),
    ], threadId: 'thread-1', runId: 'run-1');

    expect($result['events'])->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'TOOL_CALL_START', 'toolCallId' => 'call-1', 'toolCallName' => 'DeleteFile'],
        ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'call-1', 'delta' => '{"path":"a.txt"}'],
        ['type' => 'TOOL_CALL_END', 'toolCallId' => 'call-1'],
        [
            'type' => 'RUN_FINISHED',
            'threadId' => 'thread-1',
            'runId' => 'run-1',
            'outcome' => [
                'type' => 'interrupt',
                'interrupts' => [[
                    'id' => 'call-1',
                    'reason' => 'tool_call',
                    'message' => 'Deleting files requires confirmation.',
                    'toolCallId' => 'call-1',
                    'responseSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'approved' => ['type' => 'boolean'],
                            'editedArgs' => ['type' => 'object'],
                        ],
                        'required' => ['approved'],
                    ],
                ]],
            ],
        ],
    ]);
});
