<?php

use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Citation;
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
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Ai\Streaming\Protocols\AgUiProtocol;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\Agents\MultiStepToolAgent;
use Tests\Fixtures\Agents\RememberingApprovableAgent;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\FakeConversationStore;

function agUiProtocolEvents(array|Closure $events, ?string $threadId = 'thread-1', ?string $runId = 'run-1'): array
{
    $stream = $events instanceof Closure ? $events : fn () => yield from $events;

    return agUiEvents((new StreamableAgentResponse('invocation-1', $stream, new Data\Meta('anthropic', 'claude-sonnet-4-6')))
        ->usingAgUiProtocol($threadId, $runId)
        ->toResponse(request()));
}

function agUiEvents(Response $response): array
{
    $output = '';

    ob_start(function (string $buffer) use (&$output): string {
        $output .= $buffer;

        return '';
    });

    $response->sendContent();

    ob_end_clean();

    if (($frames = trim($output)) === '') {
        return [];
    }

    return collect(explode("\n\n", $frames))
        ->map(fn (string $frame) => json_decode(str_replace('data: ', '', $frame), true))
        ->all();
}

function agUiRunFinished(string $reason = 'stop'): array
{
    return [
        'type' => 'RUN_FINISHED',
        'threadId' => 'thread-1',
        'runId' => 'run-1',
        'usage' => [[
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'inputTokens' => 0,
            'outputTokens' => 0,
            'totalTokens' => 0,
            'reasoningTokens' => 0,
            'cachedInputTokens' => 0,
        ]],
        'metadata' => ['finishReason' => $reason],
    ];
}

test('a text stream emits run, step, and text message events', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextStart('event-1', 'msg-1', time()),
        new TextDelta('event-2', 'msg-1', 'Hello.', time()),
        new TextEnd('event-3', 'msg-1', time()),
        new StreamEnd('event-4', 'stop', new Usage, time()),
    ]);

    expect($events)->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'STEP_STARTED', 'stepName' => '1'],
        ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'msg-1', 'role' => 'assistant'],
        ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'msg-1', 'delta' => 'Hello.'],
        ['type' => 'TEXT_MESSAGE_END', 'messageId' => 'msg-1'],
        ['type' => 'STEP_FINISHED', 'stepName' => '1'],
        agUiRunFinished(),
    ]);
});

test('the stream is not terminated by a done frame', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-1', 'stop', new Usage, time()),
    ]);

    expect(end($events))->toBe(agUiRunFinished());
});

test('the run finished event carries the combined usage and finish reason', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-1', 'length', new Usage(promptTokens: 10, completionTokens: 5, reasoningTokens: 2), time()),
    ]);

    expect(end($events))->toBe([
        'type' => 'RUN_FINISHED',
        'threadId' => 'thread-1',
        'runId' => 'run-1',
        'usage' => [[
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'inputTokens' => 10,
            'outputTokens' => 5,
            'totalTokens' => 15,
            'reasoningTokens' => 2,
            'cachedInputTokens' => 0,
        ]],
        'metadata' => ['finishReason' => 'length'],
    ]);
});

test('a multi step run combines the usage of every step', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-1', 'tool_calls', new Usage(promptTokens: 10, completionTokens: 5), time()),
        new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-2', 'stop', new Usage(promptTokens: 20, completionTokens: 7), time()),
    ]);

    expect(end($events)['usage'][0])->toBe([
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-6',
        'inputTokens' => 30,
        'outputTokens' => 12,
        'totalTokens' => 42,
        'reasoningTokens' => 0,
        'cachedInputTokens' => 0,
    ])->and(end($events)['metadata'])->toBe(['finishReason' => 'stop']);
});

test('the response is served as an unbuffered event stream', function () {
    $response = (new StreamableAgentResponse('invocation-1', fn () => yield from [], new Data\Meta('anthropic', 'claude-sonnet-4-6')))
        ->usingProtocol(new AgUiProtocol)
        ->toResponse(request());

    expect($response->headers->get('Content-Type'))->toBe('text/event-stream')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache, no-transform');
});

test('a first turn stream emits the thread id the conversation is stored under', function () {
    app()->instance(ConversationStore::class, new FakeConversationStore);

    RememberingAssistantAgent::fake(['Fake response']);

    $user = new class
    {
        public int $id = 1;
    };

    $agent = (new RememberingAssistantAgent)->forUser($user);

    $events = agUiEvents($agent->stream('Hello')->usingProtocol(new AgUiProtocol)->toResponse(request()));

    expect($agent->currentConversation())->not->toBeNull()
        ->and($events[0]['threadId'])->toBe($agent->currentConversation())
        ->and(end($events)['threadId'])->toBe($agent->currentConversation());
});

test('an ownerless approval stream persists the thread id it emits', function () {
    app()->instance(ConversationStore::class, new FakeConversationStore);

    RememberingApprovableAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call-1', 'ApprovableNumberGenerator', [], 'Requires approval.'),
        ]),
    ]);

    $agent = new RememberingApprovableAgent;
    $events = agUiEvents($agent->stream('Generate a number')->usingProtocol(new AgUiProtocol)->toResponse(request()));

    expect($events[0]['threadId'])->toBe($agent->currentConversation())
        ->and(end($events)['threadId'])->toBe($agent->currentConversation())
        ->and(end($events)['outcome']['type'])->toBe('interrupt');
});

test('a run without an explicit identity falls back to the conversation and invocation ids', function () {
    $response = (new StreamableAgentResponse('invocation-1', fn () => yield from [
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-1', 'stop', new Usage, time()),
    ], new Data\Meta('anthropic', 'claude-sonnet-4-6')))
        ->withinConversation('conversation-1')
        ->usingProtocol(new AgUiProtocol);

    expect(agUiEvents($response->toResponse(request()))[0])->toBe([
        'type' => 'RUN_STARTED',
        'threadId' => 'conversation-1',
        'runId' => 'invocation-1',
    ]);
});

test('rendering the same response twice emits the same run', function () {
    $response = (new StreamableAgentResponse('invocation-1', fn () => yield from [
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ], new Data\Meta('anthropic', 'claude-sonnet-4-6')))->usingProtocol(new AgUiProtocol);

    $render = fn (): array => agUiEvents($response->toResponse(request()));

    expect($render())->toBe($render());
});

test('a run without a conversation generates a thread id', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-1', 'stop', new Usage, time()),
    ], threadId: null, runId: null);

    expect($events[0]['threadId'])->toBeString()->not->toBeEmpty()
        ->and($events[0]['runId'])->toBe('invocation-1');
});

test('a reasoning stream wraps the reasoning message in reasoning events', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ReasoningStart('event-1', 'reasoning-1', time()),
        new ReasoningDelta('event-2', 'reasoning-1', 'Considering the options.', time()),
        new ReasoningEnd('event-3', 'reasoning-1', time()),
        new StreamEnd('event-4', 'stop', new Usage, time()),
    ]);

    expect(collect($events)->pluck('type')->all())->toBe([
        'RUN_STARTED', 'STEP_STARTED',
        'REASONING_START', 'REASONING_MESSAGE_START',
        'REASONING_MESSAGE_CONTENT',
        'REASONING_MESSAGE_END', 'REASONING_END',
        'STEP_FINISHED', 'RUN_FINISHED',
    ])->and($events[2])->toBe(['type' => 'REASONING_START', 'messageId' => 'reasoning-1'])
        ->and($events[3])->toBe(['type' => 'REASONING_MESSAGE_START', 'messageId' => 'reasoning-1', 'role' => 'reasoning'])
        ->and($events[4])->toBe(['type' => 'REASONING_MESSAGE_CONTENT', 'messageId' => 'reasoning-1', 'delta' => 'Considering the options.']);
});

test('a tool executed within the run emits its call and result events', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], 'sunny'), true, null, time()),
        new StreamEnd('event-3', 'stop', new Usage, time()),
    ]);

    expect($events)->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'STEP_STARTED', 'stepName' => '1'],
        ['type' => 'TOOL_CALL_START', 'toolCallId' => 'call-1', 'toolCallName' => 'GetWeather'],
        ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'call-1', 'delta' => '{"city":"Lisbon"}'],
        ['type' => 'TOOL_CALL_END', 'toolCallId' => 'call-1'],
        ['type' => 'TOOL_CALL_RESULT', 'messageId' => 'event-2', 'toolCallId' => 'call-1', 'content' => 'sunny', 'role' => 'tool'],
        ['type' => 'STEP_FINISHED', 'stepName' => '1'],
        agUiRunFinished(),
    ]);
});

test('a tool call without arguments streams an empty json object', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetTime', []), time()),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ]);

    expect($events[3])->toBe(['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'call-1', 'delta' => '{}']);
});

test('a non string tool result is encoded as json content', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], ['temperature' => 21], resultId: 'result-1'), true, null, time()),
        new StreamEnd('event-3', 'stop', new Usage, time()),
    ]);

    expect($events[5])->toBe([
        'type' => 'TOOL_CALL_RESULT',
        'messageId' => 'result-1',
        'toolCallId' => 'call-1',
        'content' => '{"temperature":21}',
        'role' => 'tool',
    ]);
});

test('a failed tool call streams its error as the result content', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], null), false, 'The city is unknown.', time()),
        new StreamEnd('event-3', 'stop', new Usage, time()),
    ]);

    expect($events[5]['content'])->toBe('The city is unknown.');
});

test('a failed tool call without an error message streams a default result content', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], null), false, null, time()),
        new StreamEnd('event-3', 'stop', new Usage, time()),
    ]);

    expect($events[5]['content'])->toBe('The tool call failed.');
});

test('a multi step run wraps each provider step in step events', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new StreamEnd('event-2', 'tool_calls', new Usage, time()),
        new ToolResult('event-3', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], 'sunny'), true, null, time()),
        new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextDelta('event-4', 'msg-2', 'Sunny.', time()),
        new StreamEnd('event-5', 'stop', new Usage, time()),
    ]);

    expect(collect($events)->pluck('type')->all())->toBe([
        'RUN_STARTED', 'STEP_STARTED',
        'TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'TOOL_CALL_RESULT',
        'STEP_FINISHED', 'STEP_STARTED',
        'TEXT_MESSAGE_CONTENT',
        'STEP_FINISHED', 'RUN_FINISHED',
    ])->and(collect($events)->whereIn('type', ['STEP_STARTED', 'STEP_FINISHED'])->pluck('stepName')->all())
        ->toBe(['1', '1', '2', '2']);
});

test('a paused run finishes with an interrupt outcome for each pending approval', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'DeleteFile', ['path' => 'a.txt']), time()),
        new ToolApprovalRequest('event-2', collect([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'a.txt'], 'Destructive operation.'),
        ]), time()),
        new StreamEnd('event-3', 'tool_calls', new Usage, time()),
    ]);

    expect(end($events))->toBe([
        'type' => 'RUN_FINISHED',
        'threadId' => 'thread-1',
        'runId' => 'run-1',
        'outcome' => [
            'type' => 'interrupt',
            'interrupts' => [[
                'id' => 'call-1',
                'reason' => 'tool_call',
                'message' => 'Destructive operation.',
                'toolCallId' => 'call-1',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => ['approved' => ['type' => 'boolean']],
                    'required' => ['approved'],
                ],
            ]],
        ],
    ]);
});

test('a paused run reports its interrupt outcome even when the stream later throws', function () {
    Exceptions::fake();

    $events = agUiProtocolEvents(function () {
        yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
        yield new ToolApprovalRequest('event-1', collect([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'a.txt'], 'Destructive operation.'),
        ]), time());

        throw new RuntimeException('The conversation could not be persisted.');
    });

    expect(collect($events)->pluck('type')->all())->toBe([
        'RUN_STARTED', 'STEP_STARTED', 'STEP_FINISHED', 'RUN_FINISHED',
    ])->and($events[3]['outcome']['interrupts'][0]['id'])->toBe('call-1');

    Exceptions::assertReported(RuntimeException::class);
});

test('a pending approval without a reason omits the interrupt message', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolApprovalRequest('event-1', collect([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'a.txt']),
        ]), time()),
        new StreamEnd('event-2', 'tool_calls', new Usage, time()),
    ]);

    expect(end($events)['outcome']['interrupts'][0])->not->toHaveKey('message');
});

test('a resumed run emits the approved tool result for the prior turn tool call', function () {
    $events = agUiProtocolEvents([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextDelta('event-2', 'msg-2', 'Done.', time()),
        new StreamEnd('event-3', 'stop', new Usage, time()),
    ]);

    expect($events)->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'STEP_STARTED', 'stepName' => '1'],
        ['type' => 'TOOL_CALL_RESULT', 'messageId' => 'event-1', 'toolCallId' => 'call-1', 'content' => 'deleted', 'role' => 'tool'],
        ['type' => 'STEP_FINISHED', 'stepName' => '1'],
        ['type' => 'STEP_STARTED', 'stepName' => '2'],
        ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'msg-2', 'delta' => 'Done.'],
        ['type' => 'STEP_FINISHED', 'stepName' => '2'],
        agUiRunFinished(),
    ]);
});

test('a resumed run streams the replayed tool result without restating its call', function () {
    $events = agUiProtocolEvents([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ], threadId: null, runId: null);

    expect(collect($events)->pluck('type')->all())->toBe([
        'RUN_STARTED', 'STEP_STARTED', 'TOOL_CALL_RESULT', 'STEP_FINISHED', 'RUN_FINISHED',
    ]);
});

test('a rejected approval streams the rejection as the tool result content', function () {
    $events = agUiProtocolEvents([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'The user rejected this tool call.'), false, 'The user rejected this tool call.', time(), denied: true),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ]);

    expect($events[2])->toBe([
        'type' => 'TOOL_CALL_RESULT',
        'messageId' => 'event-1',
        'toolCallId' => 'call-1',
        'content' => 'The user rejected this tool call.',
        'role' => 'tool',
    ]);
});

test('a cited text stream emits a custom citation event', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new Citation('event-1', 'msg-1', new Data\UrlCitation('https://laravel.com/docs', 'Laravel Documentation'), time()),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ]);

    expect($events[2])->toBe([
        'type' => 'CUSTOM',
        'name' => 'citation',
        'value' => ['url' => 'https://laravel.com/docs', 'title' => 'Laravel Documentation'],
    ]);
});

test('a url citation without a title omits only the title from the custom event', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new Citation('event-1', 'msg-1', new Data\UrlCitation('https://laravel.com/docs'), time()),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ]);

    expect($events[2]['value'])->toBe(['url' => 'https://laravel.com/docs']);
});

test('an unknown citation type is skipped instead of ending the run', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new Citation('event-1', 'msg-1', new class extends Data\Citation {}, time()),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ]);

    expect(collect($events)->pluck('type')->all())->toBe([
        'RUN_STARTED', 'STEP_STARTED', 'STEP_FINISHED', 'RUN_FINISHED',
    ]);
});

test('a provider hosted tool emits a custom provider tool event', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ProviderToolEvent('event-1', 'item-1', 'web_search_call', ['query' => 'laravel'], 'completed', time(), 'anthropic'),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ]);

    expect($events[2])->toBe([
        'type' => 'CUSTOM',
        'name' => 'provider-tool',
        'value' => [
            'provider' => 'anthropic',
            'itemId' => 'item-1',
            'type' => 'web_search_call',
            'data' => ['query' => 'laravel'],
            'status' => 'completed',
        ],
    ]);
});

test('events streamed after an error are dropped because the run has ended', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new Error('event-1', 'overloaded_error', 'Overloaded', false, time()),
        new TextDelta('event-2', 'msg-1', 'Ghost.', time()),
        new StreamEnd('event-3', 'stop', new Usage, time()),
    ]);

    expect(collect($events)->pluck('type')->all())->toBe([
        'RUN_STARTED', 'STEP_STARTED', 'RUN_ERROR',
    ]);
});

test('the thread id adopts a conversation id surfaced after streaming begins', function () {
    $response = null;

    $response = new StreamableAgentResponse('invocation-1', function () use (&$response) {
        $response->withinConversation('conversation-9');

        yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
        yield new StreamEnd('event-1', 'stop', new Usage, time());
    }, new Data\Meta('anthropic', 'claude-sonnet-4-6'));

    $events = agUiEvents($response->usingProtocol(new AgUiProtocol)->toResponse(request()));

    expect($events[0])->toBe([
        'type' => 'RUN_STARTED',
        'threadId' => 'conversation-9',
        'runId' => 'invocation-1',
    ]);
});

test('a failed run emits a run error instead of a run finished event', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextStart('event-1', 'msg-1', time()),
        new Error('event-2', 'overloaded_error', 'Overloaded', false, time()),
    ]);

    expect($events)->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'STEP_STARTED', 'stepName' => '1'],
        ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'msg-1', 'role' => 'assistant'],
        ['type' => 'RUN_ERROR', 'message' => 'Overloaded', 'code' => 'overloaded_error'],
    ]);
});

test('a stream end after an error does not finish the run', function () {
    $events = agUiProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new Error('event-1', 'overloaded_error', 'Overloaded', false, time()),
        new StreamEnd('event-2', 'error', new Usage, time()),
    ]);

    expect(collect($events)->pluck('type')->all())->toBe([
        'RUN_STARTED', 'STEP_STARTED', 'RUN_ERROR',
    ]);
});

test('an exception mid run is reported and emitted as a masked run error', function () {
    Exceptions::fake();

    $events = agUiProtocolEvents(function () {
        yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
        yield new TextDelta('event-1', 'msg-1', 'Hel', time());

        throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
    });

    expect($events)->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'STEP_STARTED', 'stepName' => '1'],
        ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'msg-1', 'delta' => 'Hel'],
        ['type' => 'RUN_ERROR', 'message' => 'An error occurred.'],
    ]);

    Exceptions::assertReported(RuntimeException::class);
});

test('a provider stream error followed by the loop exception emits a single run error', function () {
    Exceptions::fake();

    $events = agUiProtocolEvents(function () {
        yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());

        $error = new Error('event-1', 'overloaded_error', 'Overloaded', false, time());

        yield $error;

        throw new StreamErrorException($error);
    });

    expect($events)->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'STEP_STARTED', 'stepName' => '1'],
        ['type' => 'RUN_ERROR', 'message' => 'Overloaded', 'code' => 'overloaded_error'],
    ]);

    Exceptions::assertNothingReported();
});

test('an unexpected exception after a run error is still reported', function () {
    Exceptions::fake();

    $events = agUiProtocolEvents(function () {
        yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
        yield new Error('event-1', 'overloaded_error', 'Overloaded', false, time());

        throw new RuntimeException('Broken pipe');
    });

    expect(collect($events)->where('type', 'RUN_ERROR')->count())->toBe(1);

    Exceptions::assertReported(RuntimeException::class);
});

test('a faked multi step agent stream emits a well formed run', function () {
    MultiStepToolAgent::fake([
        new Data\ToolCall('call-1', 'FixedNumberGenerator', []),
        'The number is 72019.',
    ]);

    $response = (new MultiStepToolAgent)->stream('Generate a number')
        ->usingProtocol(new AgUiProtocol('thread-1', 'run-1'))
        ->toResponse(request());

    $types = collect(agUiEvents($response))->pluck('type');

    // Collapse the streamed text deltas so only the run's event sequence is asserted...
    expect($types->filter(fn (string $type, int $key) => $type !== $types->get($key - 1))->values()->all())->toBe([
        'RUN_STARTED', 'STEP_STARTED',
        'TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'TOOL_CALL_RESULT',
        'STEP_FINISHED', 'STEP_STARTED',
        'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END',
        'STEP_FINISHED', 'RUN_FINISHED',
    ]);
});

test('an exception before the run starts emits a masked run error within a started run', function () {
    Exceptions::fake();

    $events = agUiProtocolEvents(function () {
        throw new RuntimeException('Broken pipe');
        yield null;
    });

    expect($events)->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'STEP_STARTED', 'stepName' => '1'],
        ['type' => 'RUN_ERROR', 'message' => 'An error occurred.'],
    ]);

    Exceptions::assertReported(RuntimeException::class);
});
