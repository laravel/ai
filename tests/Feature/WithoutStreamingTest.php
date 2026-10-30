<?php

use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\AgentUserInteraction\AgentUserInteraction;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Attributes\WithoutStreaming;
use Laravel\Ai\Jobs\BroadcastAgent;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Ai\Vercel\Vercel;
use Tests\Fixtures\Agents\HiddenResearchAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\HiddenNumberGenerator;

function withoutStreamingAgent(): AnonymousAgent
{
    return new #[WithoutStreaming(ReasoningStart::class, ReasoningDelta::class, ReasoningEnd::class)] class('You are a helpful assistant.', [], [new HiddenNumberGenerator, new FixedNumberGenerator, new HiddenResearchAgent]) extends AnonymousAgent {};
}

function streamedFrames(StreamableAgentResponse $response): array
{
    $output = '';

    ob_start(function (string $buffer) use (&$output): string {
        $output .= $buffer;

        return '';
    });

    $response->toResponse(request())->sendContent();

    ob_end_clean();

    return collect(explode("\n\n", trim($output)))
        ->map(fn (string $frame): mixed => json_decode(str_replace('data: ', '', $frame), true))
        ->filter()
        ->values()
        ->all();
}

function hiddenToolStream(array $events): StreamableAgentResponse
{
    return new StreamableAgentResponse('invocation-1', fn () => yield from $events, new Data\Meta('anthropic', 'claude-sonnet-4-6'), ['HiddenNumberGenerator']);
}

function hiddenToolHistory(): array
{
    return [
        new ConversationMessage([
            'id' => 'msg-1',
            'role' => 'assistant',
            'content' => 'Done.',
            'steps' => [[
                'reasoning' => 'Deliberating',
                'tool_calls' => [
                    ['id' => 'call-1', 'name' => 'HiddenNumberGenerator', 'arguments' => [], 'result' => '48151'],
                    ['id' => 'call-2', 'name' => 'FixedNumberGenerator', 'arguments' => [], 'result' => 72019],
                ],
            ]],
        ]),
        new AssistantMessage('', collect([new Data\ToolCall('call-3', 'HiddenNumberGenerator', [])])),
        new ToolResultMessage(collect([new Data\ToolResult('call-3', 'HiddenNumberGenerator', [], '48151')])),
        new ConversationMessage([
            'id' => 'msg-4',
            'role' => 'assistant',
            'content' => null,
            'steps' => [['tool_calls' => [['id' => 'call-4', 'name' => 'HiddenNumberGenerator', 'arguments' => [], 'approval_reason' => 'Reads every account.']]]],
        ]),
    ];
}

dataset('client streams', [
    'vercel' => [fn (StreamableAgentResponse $response): StreamableAgentResponse => $response->usingVercelDataProtocol()],
    'ag-ui' => [fn (StreamableAgentResponse $response): StreamableAgentResponse => $response->usingAgentUserInteractionProtocol()],
    'server-sent events' => [fn (StreamableAgentResponse $response): StreamableAgentResponse => $response],
]);

test('the client stream leaves out hidden tools, hidden sub-agents and the listed events', function (Closure $using): void {
    $agent = withoutStreamingAgent();

    $agent::fake([
        new Data\ToolCall('call-hidden', 'HiddenNumberGenerator', []),
        new Data\ToolCall('call-visible', 'FixedNumberGenerator', []),
        new Data\ToolCall('call-research', 'HiddenResearchAgent', ['task' => 'Dig']),
        AgentResponse::fakeWithReasoning('Deliberating', 'Done.'),
    ]);

    HiddenResearchAgent::fake(['Classified findings']);

    $response = $using($agent->stream('Find the reference'));

    expect(json_encode(streamedFrames($response)))->toContain('call-visible')->toContain('72019')->toContain('Done.')
        ->not->toContain('call-hidden')->not->toContain('48151')->not->toContain('call-research')->not->toContain('Classified')->not->toContain('Deliberating')
        ->and($response->events->whereInstanceOf(ToolResult::class)->where('preliminary', false)->pluck('toolResult.result')->values()->all())->toBe(['48151', '72019', 'Classified findings'])
        ->and($response->events->where('preliminary', true))->not->toBeEmpty()
        ->and($response->reasoning)->toBe('Deliberating');
})->with('client streams');

test('a hidden tool awaiting approval streams its call with the approval request but not its result', function (Closure $using, array $types): void {
    $frames = collect(streamedFrames($using(hiddenToolStream([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'HiddenNumberGenerator', []), time()),
        new ToolCall('event-2', new Data\ToolCall('call-2', 'HiddenNumberGenerator', ['scope' => 'all']), time()),
        new ToolResult('event-3', new Data\ToolResult('call-1', 'HiddenNumberGenerator', [], '48151'), true, null, time()),
        new ToolApprovalRequest('event-4', collect([new PendingApproval('call-2', 'HiddenNumberGenerator', ['scope' => 'all'], 'Reads every account.')]), time()),
        new StreamEnd('event-5', 'tool_calls', new TextUsage, time()),
    ]))));

    $resumed = json_encode(streamedFrames($using(hiddenToolStream([
        new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolResult('event-1', new Data\ToolResult('call-2', 'HiddenNumberGenerator', ['scope' => 'all'], '48151'), true, null, time()),
        new TextDelta('event-2', 'msg-2', 'Done.', time()),
        new StreamEnd('event-3', 'stop', new TextUsage, time()),
    ]))));

    expect($frames->pluck('type')->all())->toBe($types)
        ->and($frames->pluck('toolCallId')->filter()->unique()->values()->all())->toBe(['call-2'])
        ->and($resumed)->toContain('Done.')->not->toContain('48151');
})->with([
    'vercel' => [
        fn (StreamableAgentResponse $response): StreamableAgentResponse => $response->usingVercelDataProtocol(),
        ['start', 'start-step', 'tool-input-available', 'tool-approval-request', 'finish-step', 'finish'],
    ],
    'ag-ui' => [
        fn (StreamableAgentResponse $response): StreamableAgentResponse => $response->usingAgentUserInteractionProtocol(),
        ['RUN_STARTED', 'STEP_STARTED', 'TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'STEP_FINISHED', 'RUN_FINISHED'],
    ],
]);

test('broadcasting leaves out a hidden tool', function (Closure $broadcast): void {
    Event::fake();

    $agent = withoutStreamingAgent();

    $agent::fake([
        new Data\ToolCall('call-hidden', 'HiddenNumberGenerator', []),
        new Data\ToolCall('call-visible', 'FixedNumberGenerator', []),
        'Done.',
    ]);

    $broadcast($agent);

    Event::assertNotDispatched(AnonymousEvent::class, fn (AnonymousEvent $event): bool => str_contains(json_encode($event->broadcastWith()), 'call-hidden'));
    Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $event): bool => $event->broadcastAs() === 'tool_call' && str_contains(json_encode($event->broadcastWith()), 'call-visible'));
})->with([
    'now' => [fn (AnonymousAgent $agent): StreamableAgentResponse => $agent->broadcastNow('Find the reference', new Channel('test-channel'))],
    'queued' => [fn (AnonymousAgent $agent) => (new BroadcastAgent($agent, 'Find the reference', new Channel('test-channel')))->handle()],
]);

test('the history renderers leave out the agent hidden tools and reasoning unless a call awaits approval', function (Closure $render): void {
    expect(json_encode($render(withoutStreamingAgent())))->toContain('Done.')->toContain('call-2')->toContain('72019')->toContain('call-4')
        ->not->toContain('call-1')->not->toContain('call-3')->not->toContain('48151')->not->toContain('Deliberating')
        ->and(json_encode($render(null)))->toContain('call-1')->toContain('call-3')->toContain('48151');
})->with([
    'ui messages' => [fn (?AnonymousAgent $agent): array => Vercel::toUiMessages(hiddenToolHistory(), $agent)],
    'client state' => [fn (?AnonymousAgent $agent): array => AgentUserInteraction::toClientState(hiddenToolHistory(), $agent)],
]);
