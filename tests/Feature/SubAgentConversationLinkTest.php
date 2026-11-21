<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Streaming\Protocols\AgentUserInteractionProtocol;
use Laravel\Ai\Streaming\Protocols\VercelDataProtocol;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Vercel\Vercel;
use Tests\Fixtures\Agents\RememberingApprovableAgent;
use Tests\Fixtures\Agents\RememberingOrchestratingAgent;
use Tests\Fixtures\Agents\RememberingSubAgent;
use Tests\Fixtures\Agents\RememberingToolUsingAgent;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;

beforeEach(function (): void {
    config([
        'ai.default' => 'anthropic',
        'ai.providers.anthropic.key' => 'test-key',
        'ai.conversations.generate_title' => false,
    ]);
});

/**
 * Get the final tool results of a prompted or streamed response.
 *
 * @return Collection<int, ToolResult>
 */
function finalToolResults(AgentResponse|StreamableAgentResponse $response): Collection
{
    return $response instanceof StreamableAgentResponse
        ? $response->events->whereInstanceOf(ToolResultEvent::class)->reject->preliminary->map->toolResult->values()
        : $response->toolResults;
}

test('each delegation is linked from the parent tool call that ran it', function (string $mode): void {
    $user = (object) ['id' => 1];

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']),
        new ToolCall('call_2', 'RememberingSubAgent', ['task' => 'two']),
        'Done.',
    ]);

    RememberingSubAgent::fake(['First result', 'Second result']);

    $response = promptOrStream(
        (new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user)]))->forUser($user),
        $mode,
        'Delegate both tasks',
    );

    $links = [conversationContaining('one'), conversationContaining('two')];

    expect($links[0])->not->toBeNull()->not->toBe($links[1])
        ->and(storedToolCalls($response->conversationId))->sequence(
            fn ($call) => $call->toMatchArray(['id' => 'call_1', 'result' => 'First result', 'sub_conversation_id' => $links[0]]),
            fn ($call) => $call->toMatchArray(['id' => 'call_2', 'result' => 'Second result', 'sub_conversation_id' => $links[1]]),
        )
        ->and(finalToolResults($response)->pluck('subConversationId')->all())->toBe($links);
})->with(['prompt', 'stream']);

test('the link is left out of serialized tool results, steps and stream events', function (string $mode): void {
    $user = (object) ['id' => 1];

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']), 'Done.']);
    RememberingSubAgent::fake(['First result']);

    $response = promptOrStream(
        (new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user)]))->forUser($user),
        $mode,
        'Delegate',
    );

    $link = conversationContaining('one');
    $result = finalToolResults($response)->sole();

    expect($result->subConversationId)->toBe($link)
        ->and($result->toArray())->not->toHaveKey('sub_conversation_id')
        ->and(json_encode($result))->not->toContain($link)
        ->and(json_encode($mode === 'prompt' ? $response->steps : $response->events))->not->toContain($link)
        ->and(ToolResult::fromArray(storedToolCalls($response->conversationId)[0])->toArray())->not->toHaveKey('sub_conversation_id');
})->with(['prompt', 'stream']);

test('the link never reaches the provider, including when the parent turn is replayed', function (string $mode): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->pushResponse($mode === 'prompt'
            ? fakeAnthropicToolUseResponse('RememberingSubAgent', ['task' => 'one'], 'toolu_1')
            : fakeAnthropicStreamToolUseResponse('RememberingSubAgent', ['task' => 'one'], 'toolu_1'))
        ->pushResponse($mode === 'prompt' ? fakeAnthropicResponse('First result') : fakeAnthropicStreamResponse('First result'))
        ->pushResponse($mode === 'prompt' ? fakeAnthropicResponse('Done.') : fakeAnthropicStreamResponse('Done.'))
        ->pushResponse(fakeAnthropicResponse('Done again.')),
    ]);

    $orchestrator = fn () => new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user)]);

    $first = promptOrStream($orchestrator()->forUser($user), $mode, 'Delegate');
    $second = $orchestrator()->continue($first->conversationId, $user)->prompt('Again');

    $link = conversationContaining('one');

    $requests = Http::recorded()->map(fn (array $pair) => $pair[0]->data())->values();

    $toolResultBlock = fn (array $request) => collect($request['messages'])
        ->flatMap(fn (array $message) => is_array($message['content']) ? $message['content'] : [])
        ->firstWhere('type', 'tool_result');

    expect($second->text)->toBe('Done again.')
        ->and($link)->not->toBeNull()
        ->and($requests)->toHaveCount(4)
        ->and($toolResultBlock($requests[2]))->toMatchArray(['tool_use_id' => 'toolu_1', 'content' => 'First result'])
        ->and($toolResultBlock($requests[3]))->toMatchArray(['tool_use_id' => 'toolu_1', 'content' => 'First result'])
        ->and(json_encode($requests))->not->toContain($link)->not->toContain('sub_conversation_id')
        ->and(storedToolCalls($first->conversationId)[0]['sub_conversation_id'])->toBe($link);

    $replayed = collect($orchestrator()->continue($first->conversationId, $user)->messages())
        ->whereInstanceOf(ToolResultMessage::class)
        ->flatMap->toolResults
        ->first();

    expect($replayed->result)->toBe('First result')
        ->and($replayed->subConversationId)->toBeNull();
})->with(['prompt', 'stream']);

test('the link never reaches the stream protocols, UI messages or tool listeners', function (string $protocol): void {
    $user = (object) ['id' => 1];
    $invoked = [];

    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$invoked): void {
        $invoked[] = $event->result;
    });

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']), 'Done.']);
    RememberingSubAgent::fake(['First result']);

    $stream = (new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user)]))->forUser($user)->stream('Delegate');

    $response = $stream->usingProtocol(new $protocol)->toResponse(request());

    $output = '';

    ob_start(function (string $buffer) use (&$output): string {
        $output .= $buffer;

        return '';
    });

    $response->sendContent();

    ob_end_clean();

    $link = conversationContaining('one');

    $uiMessages = Vercel::toUiMessages((new RememberingOrchestratingAgent)->continue($stream->conversationId, $user)->messages());

    expect($link)->not->toBeNull()
        ->and(storedToolCalls($stream->conversationId)[0]['sub_conversation_id'])->toBe($link)
        ->and($output)->toContain('First result')->not->toContain($link)
        ->and(json_encode($uiMessages))->toContain('First result')->not->toContain($link)
        ->and($invoked)->toBe(['First result']);
})->with([
    'Vercel' => VercelDataProtocol::class,
    'AG-UI' => AgentUserInteractionProtocol::class,
]);

test('a sub-agent that fails after its first step is linked to its failed conversation', function (string $mode): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->pushResponse($mode === 'prompt' ? fakeAnthropicToolUseResponse('FixedNumberGenerator') : fakeAnthropicStreamToolUseResponse('FixedNumberGenerator'))
        ->pushStatus(500)
        ->pushStatus(500)
        ->pushStatus(500),
    ]);

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'RememberingToolUsingAgent', ['task' => 'one']), 'Done.']);

    $response = promptOrStream(
        (new RememberingOrchestratingAgent([(new RememberingToolUsingAgent)->forUser($user)]))->forUser($user),
        $mode,
        'Delegate',
    );

    $link = conversationContaining('one');

    expect($link)->not->toBeNull()
        ->and(storedToolCalls($response->conversationId)[0])->toMatchArray(['failed' => true, 'sub_conversation_id' => $link])
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $link)->where('role', 'assistant')->value('status'))->toBe('failed');
})->with(['prompt', 'stream']);

test('a sub-agent that fails before its first step is not linked', function (string $mode): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()->pushStatus(500)->pushStatus(500)->pushStatus(500)]);

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'RememberingToolUsingAgent', ['task' => 'one']), 'Done.']);

    $response = promptOrStream(
        (new RememberingOrchestratingAgent([(new RememberingToolUsingAgent)->forUser($user)]))->forUser($user),
        $mode,
        'Delegate',
    );

    expect(storedToolCalls($response->conversationId)[0])->toMatchArray(['failed' => true])->not->toHaveKey('sub_conversation_id')
        ->and(DB::table('agent_conversations')->pluck('id')->all())->toBe([$response->conversationId]);
})->with(['prompt', 'stream']);

test('a sub-agent paused on an approval is linked to its paused conversation', function (string $mode): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->pushResponse($mode === 'prompt' ? fakeAnthropicToolUseResponse('ApprovableNumberGenerator') : fakeAnthropicStreamToolUseResponse('ApprovableNumberGenerator')),
    ]);

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'RememberingApprovableAgent', ['task' => 'one']), 'Done.']);

    $response = promptOrStream((new RememberingOrchestratingAgent([new RememberingApprovableAgent]))->forUser($user), $mode, 'Delegate');

    $link = conversationContaining('one');

    expect($link)->not->toBeNull()
        ->and(storedToolCalls($response->conversationId)[0])->toMatchArray(['result' => '', 'sub_conversation_id' => $link])
        ->and(Conversation::find($link)->participant_id)->toBeNull()
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $link)->where('role', 'assistant')->value('status'))->toBe('paused');
})->with(['prompt', 'stream']);

test('a parent that fails after delegating keeps the link on its failed turn', function (): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->pushResponse(fakeAnthropicToolUseResponse('RememberingSubAgent', ['task' => 'one'], 'toolu_1'))
        ->pushResponse(fakeAnthropicResponse('First result'))
        ->pushStatus(500)
        ->pushStatus(500)
        ->pushStatus(500),
    ]);

    $orchestrator = (new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user)]))->forUser($user);

    expect(fn () => $orchestrator->prompt('Delegate'))->toThrow(RequestException::class);

    $failed = DB::table('agent_conversation_messages')->where('role', 'assistant')->where('status', 'failed')->sole();

    expect(storedToolCalls($failed->conversation_id)[0])->toMatchArray([
        'id' => 'toolu_1',
        'result' => 'First result',
        'sub_conversation_id' => conversationContaining('one'),
    ]);
});

test('a delegation approved when the parent resumes is linked', function (string $mode): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->pushResponse($mode === 'prompt'
            ? fakeAnthropicToolUseResponse('RememberingSubAgent', ['task' => 'one'], 'toolu_1')
            : fakeAnthropicStreamToolUseResponse('RememberingSubAgent', ['task' => 'one'], 'toolu_1'))
        ->pushResponse(fakeAnthropicResponse('First result'))
        ->pushResponse($mode === 'prompt' ? fakeAnthropicResponse('Done.') : fakeAnthropicStreamResponse('Done.')),
    ]);

    $orchestrator = fn () => new RememberingOrchestratingAgent([
        (new AgentTool((new RememberingSubAgent)->forUser($user)))->requireApproval('Check the task first.'),
    ]);

    $paused = promptOrStream($orchestrator()->forUser($user), $mode, 'Delegate');

    expect(storedToolCalls($paused->conversationId)[0])->toHaveKey('approval_reason')->not->toHaveKey('sub_conversation_id')
        ->and(conversationContaining('one'))->toBeNull();

    promptOrStream($orchestrator()->continue($paused->conversationId, $user), $mode, Decisions::from(['toolu_1' => true]));

    expect(storedToolCalls($paused->conversationId))->toHaveCount(1)
        ->and(storedToolCalls($paused->conversationId)[0])->toMatchArray([
            'id' => 'toolu_1',
            'result' => 'First result',
            'sub_conversation_id' => conversationContaining('one'),
        ])
        ->and(storedMessageContents(conversationContaining('one')))->toBe(['one', 'First result']);
})->with(['prompt', 'stream']);

test('a delegation denied when the parent resumes is not run or linked', function (string $mode): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->pushResponse($mode === 'prompt'
            ? fakeAnthropicToolUseResponse('RememberingSubAgent', ['task' => 'one'], 'toolu_1')
            : fakeAnthropicStreamToolUseResponse('RememberingSubAgent', ['task' => 'one'], 'toolu_1'))
        ->pushResponse($mode === 'prompt' ? fakeAnthropicResponse('Done.') : fakeAnthropicStreamResponse('Done.')),
    ]);

    $orchestrator = fn () => new RememberingOrchestratingAgent([
        (new AgentTool((new RememberingSubAgent)->forUser($user)))->requireApproval('Check the task first.'),
    ]);

    $paused = promptOrStream($orchestrator()->forUser($user), $mode, 'Delegate');

    promptOrStream($orchestrator()->continue($paused->conversationId, $user), $mode, Decisions::from(['toolu_1' => false]));

    expect(storedToolCalls($paused->conversationId))->toHaveCount(1)
        ->and(storedToolCalls($paused->conversationId)[0])->not->toHaveKey('sub_conversation_id')
        ->and(DB::table('agent_conversations')->pluck('id')->all())->toBe([$paused->conversationId]);
})->with(['prompt', 'stream']);

test('a delegation run alongside a paused tool call keeps its link when the parent resumes', function (): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'RememberingSubAgent', 'input' => ['task' => 'one']],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'ApprovableNumberGenerator', 'input' => (object) []],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])
        ->pushResponse(fakeAnthropicResponse('First result'))
        ->pushResponse(fakeAnthropicResponse('Done.')),
    ]);

    $orchestrator = fn () => new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user), new ApprovableNumberGenerator]);

    $paused = $orchestrator()->forUser($user)->prompt('Delegate and generate a number');

    $link = conversationContaining('one');

    expect($link)->not->toBeNull()
        ->and(collect(storedToolCalls($paused->conversationId))->firstWhere('id', 'toolu_1'))->toMatchArray(['sub_conversation_id' => $link]);

    $orchestrator()->continue($paused->conversationId, $user)->prompt(Decisions::from(['toolu_2' => true]));

    $calls = collect(storedToolCalls($paused->conversationId))->keyBy('id');

    expect($calls['toolu_1'])->toMatchArray(['result' => 'First result', 'sub_conversation_id' => $link])
        ->and($calls['toolu_2'])->toMatchArray(['result' => '72019'])->not->toHaveKey('sub_conversation_id');
});

test('delegations in one step, and a later step reusing one of their IDs, each get their own link', function (): void {
    $user = (object) ['id' => 1];

    // A tool call ID is only unique within its turn, so a later step may be given one again...
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'RememberingSubAgent', 'input' => ['task' => 'one']],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'RememberingSubAgent', 'input' => ['task' => 'two']],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])
        ->pushResponse(fakeAnthropicResponse('First result'))
        ->pushResponse(fakeAnthropicResponse('Second result'))
        ->pushResponse(fakeAnthropicToolUseResponse('RememberingSubAgent', ['task' => 'three'], 'toolu_1'))
        ->pushResponse(fakeAnthropicResponse('Third result'))
        ->pushResponse(fakeAnthropicResponse('Done.')),
    ]);

    $response = (new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user)]))->forUser($user)->prompt('Delegate');

    $links = [conversationContaining('one'), conversationContaining('two'), conversationContaining('three')];

    expect($links)->each->not->toBeNull()
        ->and(array_unique($links))->toHaveCount(3)
        ->and($response->toolResults->pluck('subConversationId')->all())->toBe($links)
        ->and(collect(storedToolCalls($response->conversationId))->pluck('sub_conversation_id')->all())->toBe($links);
});
