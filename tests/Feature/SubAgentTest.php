<?php

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\AgentTool;
use Tests\Fixtures\Agents\DelegatingAgent;
use Tests\Fixtures\Agents\MiddleManagerAgent;
use Tests\Fixtures\Agents\OrchestratorAgent;
use Tests\Fixtures\Agents\ParentModelResearchAgent;
use Tests\Fixtures\Agents\ResearchAgent;

test('agent returned from tools is invoked when called by parent agent', function (): void {
    DelegatingAgent::fake([
        new ToolCall('call_123', 'research_agent', ['task' => 'Research Laravel']),
        'Research delegated.',
    ]);

    ResearchAgent::fake(['Research result']);

    $response = (new DelegatingAgent)->prompt('Delegate research about Laravel');

    DelegatingAgent::assertPrompted('Delegate research about Laravel');
    ResearchAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Research Laravel');

    expect($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls->first()->name)->toBe('research_agent')
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults->first()->result)->toBe('Research result');
});

test('research agent can be faked independently', function (): void {
    ResearchAgent::fake(['Research result']);

    $response = (new ResearchAgent)->prompt('Research topic');

    expect($response->text)->toBe('Research result');
});

test('agent tool uses name and description from agent when defined', function (): void {
    $tool = new AgentTool(new ResearchAgent);

    expect($tool->name())->toBe('research_agent')
        ->and($tool->description())->toBe('Research a topic in depth and return a summary.');
});

test('agent tool falls back to class basename for name when has tool metadata is not implemented', function (): void {
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return '';
        }
    };

    $tool = new AgentTool($agent);
    $name = $tool->name();

    expect($name)->not->toBeEmpty()
        ->and((string) $tool->description())
        ->toStartWith("Delegates a task to the {$name} sub-agent");
});

test('agent tool falls back to a generic description that does not leak instructions', function (): void {
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a silent agent with very long internal instructions that should never be sent to the parent LLM as a tool description.';
        }
    };

    $tool = new AgentTool($agent);

    expect((string) $tool->description())
        ->toStartWith('Delegates a task to the')
        ->and((string) $tool->description())
        ->toContain('sub-agent')
        ->not->toContain('long internal instructions');
});

test('framework wraps an agent in tools automatically when resolving', function (): void {
    $tools = (new DelegatingAgent)->tools();

    $resolved = array_map(
        fn ($tool) => $tool instanceof Agent ? new AgentTool($tool) : $tool,
        [...$tools],
    );

    expect($resolved[0])->toBeInstanceOf(AgentTool::class)
        ->and($resolved[0]->agent())->toBeInstanceOf(ResearchAgent::class);
});

test('nested agent delegates through a middle manager to a research agent', function (): void {
    OrchestratorAgent::fake([
        new ToolCall('call_001', 'middle_manager', ['task' => 'Deep-dive on Laravel caching']),
        'Delegated to middle manager.',
    ]);

    MiddleManagerAgent::fake([
        new ToolCall('call_002', 'research_agent', ['task' => 'Research Laravel caching internals']),
        'Research delegated.',
    ]);

    ResearchAgent::fake(['Deep research result']);

    $response = (new OrchestratorAgent)->prompt('Do a deep dive on Laravel caching');

    OrchestratorAgent::assertPrompted('Do a deep dive on Laravel caching');
    MiddleManagerAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Deep-dive on Laravel caching');
    ResearchAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Research Laravel caching internals');

    expect($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls->first()->name)->toBe('middle_manager')
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults->first()->result)->toBe('Research delegated.');
});

test('a sub agent using the parent model runs on the provider and model of the run that delegated to it', function (): void {
    DelegatingAgent::fake([
        new ToolCall('call_1', 'research_agent', ['task' => 'Research Laravel']),
        'Research delegated.',
    ]);

    ParentModelResearchAgent::fake(['Research result']);

    (new DelegatingAgent)
        ->withTools([new ParentModelResearchAgent])
        ->prompt('Delegate research about Laravel', provider: 'anthropic', model: 'parent-model');

    ParentModelResearchAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->provider->name() === 'anthropic'
        && $prompt->model === 'parent-model');
});

test('a sub agent using the parent model inherits it from a streamed run', function (): void {
    DelegatingAgent::fake([
        new ToolCall('call_1', 'research_agent', ['task' => 'Research Laravel']),
        'Research delegated.',
    ]);

    ParentModelResearchAgent::fake(['Research result']);

    iterator_to_array((new DelegatingAgent)
        ->withTools([new ParentModelResearchAgent])
        ->stream('Delegate research about Laravel', provider: 'anthropic', model: 'parent-model'), false);

    ParentModelResearchAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->provider->name() === 'anthropic'
        && $prompt->model === 'parent-model');
});

test('a sub agent without the parent model attribute keeps running on its own configuration', function (): void {
    DelegatingAgent::fake([
        new ToolCall('call_1', 'research_agent', ['task' => 'Research Laravel']),
        'Research delegated.',
    ]);

    ResearchAgent::fake(['Research result']);

    (new DelegatingAgent)->prompt('Delegate research about Laravel', provider: 'anthropic', model: 'parent-model');

    ResearchAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->provider->name() === config('ai.default')
        && $prompt->model === $prompt->provider->defaultTextModel());
});
