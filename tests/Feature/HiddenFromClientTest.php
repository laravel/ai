<?php

use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AgentUserInteraction\AgentUserInteraction;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Jobs\BroadcastAgent;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Ai\Vercel\Vercel;
use Tests\Fixtures\Agents\HiddenResearchAgent;
use Tests\Fixtures\Agents\RememberingHiddenToolAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\HiddenNumberGenerator;

function hiddenToolAgent(): AnonymousAgent
{
    return new AnonymousAgent('You are a helpful assistant.', [], [new HiddenNumberGenerator, new FixedNumberGenerator, new HiddenResearchAgent]);
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

function hiddenToolHistory(bool $flagged): array
{
    $hidden = $flagged ? ['hidden' => true] : [];

    return [
        new ConversationMessage([
            'id' => 'msg-1',
            'role' => 'assistant',
            'content' => 'Done.',
            'steps' => [['tool_calls' => [
                ['id' => 'call-1', 'name' => 'HiddenNumberGenerator', 'arguments' => [], 'result' => '48151', ...$hidden],
                ['id' => 'call-2', 'name' => 'FixedNumberGenerator', 'arguments' => [], 'result' => 72019],
            ]]],
        ]),
        new ConversationMessage([
            'id' => 'msg-2',
            'role' => 'assistant',
            'content' => null,
            'steps' => [['tool_calls' => [['id' => 'call-3', 'name' => 'HiddenNumberGenerator', 'arguments' => [], 'result' => '48151', ...$hidden]]]],
        ]),
        ...($flagged ? [] : [
            new AssistantMessage('', collect([new Data\ToolCall('call-5', 'HiddenNumberGenerator', [])])),
            new ToolResultMessage(collect([new Data\ToolResult('call-5', 'HiddenNumberGenerator', [], '48151')])),
        ]),
        new ConversationMessage([
            'id' => 'msg-4',
            'role' => 'assistant',
            'content' => null,
            'steps' => [['tool_calls' => [['id' => 'call-4', 'name' => 'HiddenNumberGenerator', 'arguments' => [], 'approval_reason' => 'Reads every account.', ...$hidden]]]],
        ]),
    ];
}

dataset('client streams', [
    'vercel' => [fn (StreamableAgentResponse $response): StreamableAgentResponse => $response->usingVercelDataProtocol()],
    'ag-ui' => [fn (StreamableAgentResponse $response): StreamableAgentResponse => $response->usingAgentUserInteractionProtocol()],
    'server-sent events' => [fn (StreamableAgentResponse $response): StreamableAgentResponse => $response],
]);

dataset('history renderers', [
    'ui messages' => [fn (array $messages, ?AnonymousAgent $agent = null): array => Vercel::toUiMessages($messages, $agent)],
    'client state' => [fn (array $messages, ?AnonymousAgent $agent = null): array => AgentUserInteraction::toClientState($messages, $agent)],
]);

test('the client stream leaves out hidden tools and hidden sub-agents', function (Closure $using): void {
    $agent = hiddenToolAgent();

    $agent::fake([
        new Data\ToolCall('call-hidden', 'HiddenNumberGenerator', []),
        new Data\ToolCall('call-visible', 'FixedNumberGenerator', []),
        new Data\ToolCall('call-research', 'HiddenResearchAgent', ['task' => 'Dig']),
        'Done.',
    ]);

    HiddenResearchAgent::fake(['Classified findings']);

    $response = $using($agent->stream('Find the reference'));

    expect(json_encode(streamedFrames($response)))->toContain('call-visible')->toContain('72019')->toContain('Done.')
        ->not->toContain('call-hidden')->not->toContain('48151')->not->toContain('call-research')->not->toContain('Classified')
        ->and($response->events->whereInstanceOf(ToolResult::class)->where('preliminary', false)->pluck('toolResult.result')->values()->all())->toBe(['48151', '72019', 'Classified findings'])
        ->and($response->events->where('preliminary', true))->not->toBeEmpty();
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

test('broadcasting leaves out hidden tools and hidden sub-agents', function (Closure $broadcast, Closure $agent): void {
    Event::fake();

    $agent = $agent();

    $agent::fake([
        new Data\ToolCall('call-hidden', 'HiddenNumberGenerator', []),
        new Data\ToolCall('call-visible', 'FixedNumberGenerator', []),
        new Data\ToolCall('call-research', 'HiddenResearchAgent', ['task' => 'Dig']),
        'Done.',
    ]);

    HiddenResearchAgent::fake(['Classified findings']);

    $broadcast($agent);

    Event::assertNotDispatched(AnonymousEvent::class, fn (AnonymousEvent $event): bool => str_contains(json_encode($event->broadcastWith()), 'call-hidden') || str_contains(json_encode($event->broadcastWith()), 'call-research'));
    Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $event): bool => $event->broadcastAs() === 'tool_call' && str_contains(json_encode($event->broadcastWith()), 'call-visible'));
})->with([
    'now' => [fn (AnonymousAgent $agent): StreamableAgentResponse => $agent->broadcastNow('Find the reference', new Channel('test-channel'))],
    'queued' => [fn (AnonymousAgent $agent) => (new BroadcastAgent($agent, 'Find the reference', new Channel('test-channel')))->handle()],
])->with([
    'declared tools' => [fn (): AnonymousAgent => hiddenToolAgent()],
    'runtime tools' => [fn (): AnonymousAgent => (new AnonymousAgent('You are a helpful assistant.', [], []))->withTools([new HiddenNumberGenerator, new FixedNumberGenerator, new HiddenResearchAgent])],
]);

test('a stored turn flags the calls of hidden tools and hidden sub-agents', function (Closure $run): void {
    RememberingHiddenToolAgent::fake([
        new Data\ToolCall('call-hidden', 'HiddenNumberGenerator', []),
        new Data\ToolCall('call-visible', 'FixedNumberGenerator', []),
        new Data\ToolCall('call-research', 'HiddenResearchAgent', ['task' => 'Dig']),
        'Done.',
    ]);

    HiddenResearchAgent::fake(['Classified findings']);

    $run((new RememberingHiddenToolAgent)->forUser((object) ['id' => 1]));

    $message = ConversationMessage::query()->where('role', 'assistant')->sole();

    expect(collect($message->tool_calls)->pluck('hidden', 'id')->all())->toBe(['call-hidden' => true, 'call-visible' => null, 'call-research' => true])
        ->and(collect($message->tool_results)->pluck('hidden', 'id')->all())->toBe(['call-hidden' => true, 'call-visible' => null, 'call-research' => true]);
})->with([
    'prompt' => [fn (RememberingHiddenToolAgent $agent) => $agent->prompt('Find the reference')],
    'stream' => [fn (RememberingHiddenToolAgent $agent) => $agent->stream('Find the reference')->each(fn (): true => true)],
]);

test('a turn that dies after a hidden tool ran flags its call', function (): void {
    Config::set('ai.conversations.generate_title', false);
    Config::set('ai.providers.anthropic.key', 'test-key');

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'HiddenNumberGenerator', 'input' => (object) []]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])
        ->pushStatus(500)
        ->pushStatus(500)
        ->pushStatus(500),
    ]);

    expect(fn () => (new RememberingHiddenToolAgent)->forUser((object) ['id' => 1])->prompt('Go', provider: 'anthropic'))
        ->toThrow(RequestException::class);

    $message = ConversationMessage::query()->where('role', 'assistant')->sole();

    expect(collect($message->tool_calls)->pluck('result', 'id')->all())->toBe(['toolu_1' => '48151'])
        ->and(collect($message->tool_calls)->pluck('hidden', 'id')->all())->toBe(['toolu_1' => true]);
});

test('the history renderers leave out flagged calls without the agent unless a call awaits approval', function (Closure $render): void {
    expect(json_encode($render(hiddenToolHistory(flagged: true))))->toContain('Done.')->toContain('call-2')->toContain('72019')->toContain('call-4')
        ->not->toContain('call-1')->not->toContain('call-3')->not->toContain('48151');
})->with('history renderers');

test('given the agent, the history renderers leave out its hidden tools in rows stored without the flag', function (Closure $render): void {
    expect(json_encode($render(hiddenToolHistory(flagged: false), hiddenToolAgent())))->toContain('Done.')->toContain('call-2')->toContain('72019')->toContain('call-4')
        ->not->toContain('call-1')->not->toContain('call-3')->not->toContain('call-5')->not->toContain('48151')
        ->and(json_encode($render(hiddenToolHistory(flagged: false))))->toContain('call-1')->toContain('call-3')->toContain('call-5')->toContain('48151');
})->with('history renderers');

test('the ui messages leave out an assistant message whose tool calls are all hidden', function (bool $flagged): void {
    expect(array_column(Vercel::toUiMessages(hiddenToolHistory($flagged), $flagged ? null : hiddenToolAgent()), 'id'))->toBe(['msg-1', 'msg-4']);
})->with([
    'flagged' => [true],
    'by name' => [false],
]);
