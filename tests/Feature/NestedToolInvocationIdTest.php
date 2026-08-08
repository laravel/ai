<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\AgentTool;
use Tests\Fixtures\Tools\FixedNumberGenerator;

class NestedResearchAgent implements Agent, CanActAsTool, HasTools
{
    use Promptable;

    public function name(): string
    {
        return 'research_agent';
    }

    public function description(): string
    {
        return 'Research a topic in depth and return a summary.';
    }

    public function instructions(): string
    {
        return 'You are a research agent. Summarize your findings concisely.';
    }

    public function tools(): iterable
    {
        return [new FixedNumberGenerator];
    }
}

class NestedDelegatingAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a project manager that delegates research tasks to your research_agent sub-agent.';
    }

    public function tools(): iterable
    {
        return [new NestedResearchAgent];
    }
}

function nestedFakeOpenAiToolCallResponse(string $callId, string $name, string $arguments): PromiseInterface
{
    return Http::response([
        'id' => 'resp_tool_'.$callId,
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_'.$callId,
            'call_id' => $callId,
            'name' => $name,
            'arguments' => $arguments,
            'status' => 'completed',
        ]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function nestedFakeOpenAiTextResponse(string $text): PromiseInterface
{
    return Http::response([
        'id' => 'resp_123',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => $text]],
        ]],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ]);
}

test('tool invocation ids correlate when a tool invokes a sub-agent', function (): void {
    Http::fake([
        '*' => Http::sequence([
            nestedFakeOpenAiToolCallResponse('call_outer', 'research_agent', '{"task":"Research Laravel"}'),
            nestedFakeOpenAiToolCallResponse('call_inner', 'FixedNumberGenerator', '{}'),
            nestedFakeOpenAiTextResponse('Research complete.'),
            nestedFakeOpenAiTextResponse('Delegated.'),
        ]),
    ]);

    config(['ai.providers.openai.key' => 'test-key']);

    $invoking = [];
    $invoked = [];

    // The sub-agent is wrapped in an AgentTool, so the tool type distinguishes the outer
    // invocation from the leaf tool the sub-agent calls while the outer one is still running.
    Event::listen(InvokingTool::class, function (InvokingTool $event) use (&$invoking): void {
        $invoking[$event->tool instanceof AgentTool ? 'outer' : 'inner'] = $event->toolInvocationId;
    });

    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$invoked): void {
        $invoked[$event->tool instanceof AgentTool ? 'outer' : 'inner'] = $event->toolInvocationId;
    });

    (new NestedDelegatingAgent)->prompt('Delegate research about Laravel', provider: 'openai');

    expect($invoked)->toHaveKeys(['outer', 'inner'])
        ->and($invoked['inner'])->toBe($invoking['inner'])
        ->and($invoked['outer'])->toBe($invoking['outer'])
        ->and($invoked['outer'])->not->toBe($invoked['inner']);
});
