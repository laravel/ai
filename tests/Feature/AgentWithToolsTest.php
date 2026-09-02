<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Vercel\Vercel;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\NamedToolAgent;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\NamedTool;

test('runtime tools replace the tools the agent declares', function (): void {
    NamedToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Done.',
    ]);

    $response = (new NamedToolAgent)
        ->withTools([new FixedNumberGenerator])
        ->prompt('Generate a number');

    expect($response->toolResults->pluck('result')->all())->toBe(['72019']);
});

test('a runtime tool closure receives the declared tools', function (): void {
    NamedToolAgent::fake([
        new ToolCall('call_1', 'custom_named_tool', []),
        new ToolCall('call_2', 'FixedNumberGenerator', []),
        'Done.',
    ]);

    $response = (new NamedToolAgent)
        ->withTools(fn (array $tools): array => [...$tools, new FixedNumberGenerator])
        ->prompt('Use both tools');

    expect($response->toolResults->pluck('result')->all())->toBe(['ok', '72019']);
});

test('runtime tools reach an agent that does not implement has tools', function (): void {
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }
    };

    Ai::fakeAgent($agent::class, [
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Done.',
    ]);

    $response = $agent->withTools([new FixedNumberGenerator])->prompt('Generate a number');

    expect($response->toolResults->first()->result)->toBe('72019');
});

test('runtime tools persist across invocations of the same instance', function (): void {
    $agent = (new AssistantAgent)->withTools([new FixedNumberGenerator]);

    foreach (range(1, 2) as $attempt) {
        AssistantAgent::fake([
            new ToolCall('call_'.$attempt, 'FixedNumberGenerator', []),
            'Done.',
        ]);

        expect($agent->prompt('Generate a number')->toolResults->first()->result)->toBe('72019');
    }
});

test('with tools is chainable and the last call wins', function (): void {
    NamedToolAgent::fake([
        new ToolCall('call_1', 'custom_named_tool', []),
        'Done.',
    ]);

    $response = (new NamedToolAgent)
        ->withTools([new FixedNumberGenerator])
        ->withTools([new NamedTool])
        ->prompt('Use the tool');

    expect($response->toolResults->first()->result)->toBe('ok');
});

test('a paused approval resumes only when the runtime tools are re-applied', function (): void {
    ApprovableNumberGenerator::$invocations = 0;

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_2',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $chat = Vercel::chat([
        ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Generate a number']]],
        ['id' => 'm2', 'role' => 'assistant', 'parts' => [
            ['type' => 'tool-ApprovableNumberGenerator', 'toolCallId' => 'toolu_1', 'state' => 'approval-responded', 'input' => [], 'approval' => ['id' => 'toolu_1', 'approved' => true]],
        ]],
    ]);

    expect(fn () => (new AssistantAgent)
        ->withMessages($chat->history())
        ->prompt($chat, provider: 'anthropic'))->toThrow(NoSuchToolException::class);

    $response = (new AssistantAgent)
        ->withTools([new ApprovableNumberGenerator])
        ->withMessages($chat->history())
        ->prompt($chat, provider: 'anthropic');

    expect(ApprovableNumberGenerator::$invocations)->toBe(1)
        ->and($response->text)->toBe('The number is 72019.');
});

test('middleware may swap the tools for a step', function (): void {
    $agent = new class implements Agent, HasMiddleware, HasTools
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function tools(): iterable
        {
            return [new NamedTool];
        }

        public function middleware(): array
        {
            return [
                fn (PendingStep $step, Closure $next) => $next($step->withTools([new FixedNumberGenerator])),
            ];
        }
    };

    Ai::fakeAgent($agent::class, [
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Done.',
    ]);

    expect($agent->prompt('Generate a number')->toolResults->first()->result)->toBe('72019');
});

test('a resume prompt keeps its tools when middleware attempts a swap', function (): void {
    $prompt = new AgentPrompt(
        new AssistantAgent,
        '',
        [],
        Ai::textProviderFor(new AssistantAgent, 'anthropic'),
        'claude-sonnet-4-6',
        approvalDecisions: Decisions::from(['toolu_1' => true]),
        tools: [new ApprovableNumberGenerator],
    );

    expect($prompt->withTools([new NamedTool]))->toBe($prompt);
});
