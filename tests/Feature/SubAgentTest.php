<?php

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Agents\DelegatingAgent;
use Tests\Fixtures\Agents\MiddleManagerAgent;
use Tests\Fixtures\Agents\OrchestratorAgent;
use Tests\Fixtures\Agents\ResearchAgent;
use Tests\Fixtures\Agents\StreamingMiddleManagerAgent;
use Tests\Fixtures\Agents\StreamingOrchestratorAgent;
use Tests\Fixtures\Agents\StreamingResearchAgent;

test('agent returned from tools is invoked when called by parent agent', function () {
    DelegatingAgent::fake([
        new ToolCall('call_123', 'research_agent', ['task' => 'Research Laravel']),
        'Research delegated.',
    ]);

    ResearchAgent::fake(['Research result']);

    $response = (new DelegatingAgent)->prompt('Delegate research about Laravel');

    DelegatingAgent::assertPrompted('Delegate research about Laravel');
    ResearchAgent::assertPrompted(function (AgentPrompt $prompt) {
        return $prompt->prompt === 'Research Laravel';
    });

    expect($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls->first()->name)->toBe('research_agent')
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults->first()->result)->toBe('Research result');
});

test('research agent can be faked independently', function () {
    ResearchAgent::fake(['Research result']);

    $response = (new ResearchAgent)->prompt('Research topic');

    expect($response->text)->toBe('Research result');
});

test('agent tool uses name and description from agent when defined', function () {
    $tool = new AgentTool(new ResearchAgent);

    expect($tool->name())->toBe('research_agent')
        ->and($tool->description())->toBe('Research a topic in depth and return a summary.');
});

test('agent tool falls back to class basename for name when has tool metadata is not implemented', function () {
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

test('agent tool falls back to a generic description that does not leak instructions', function () {
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

test('framework wraps an agent in tools automatically when resolving', function () {
    $tools = (new DelegatingAgent)->tools();

    $resolved = array_map(
        fn ($tool) => $tool instanceof Agent ? new AgentTool($tool) : $tool,
        [...$tools],
    );

    expect($resolved[0])->toBeInstanceOf(AgentTool::class)
        ->and($resolved[0]->agent())->toBeInstanceOf(ResearchAgent::class);
});

test('nested agent delegates through a middle manager to a research agent', function () {
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
    MiddleManagerAgent::assertPrompted(function (AgentPrompt $prompt) {
        return $prompt->prompt === 'Deep-dive on Laravel caching';
    });
    ResearchAgent::assertPrompted(function (AgentPrompt $prompt) {
        return $prompt->prompt === 'Research Laravel caching internals';
    });

    expect($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls->first()->name)->toBe('middle_manager')
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults->first()->result)->toBe('Research delegated.');
});

test('agent tool keeps sub agent streaming opt in', function () {
    ResearchAgent::fake(['Research result']);

    $stream = (new AgentTool(new ResearchAgent))->streamHandle(new Request([
        'task' => 'Research topic',
    ]));

    expect(iterator_to_array($stream, false))->toBeEmpty()
        ->and($stream->getReturn())->toBe('Research result');
});

test('streaming sub agent events are stamped with nested tool call provenance', function () {
    StreamingOrchestratorAgent::fake([
        new ToolCall('call_001', 'streaming_middle_manager', ['task' => 'Deep-dive on Laravel caching']),
        'Top done',
    ]);

    StreamingMiddleManagerAgent::fake([
        new ToolCall('call_002', 'streaming_research_agent', ['task' => 'Research Laravel caching internals']),
        'Middle done',
    ]);

    StreamingResearchAgent::fake(['Deep research result']);

    $response = (new StreamingOrchestratorAgent)->stream('Do a deep dive on Laravel caching');
    $events = iterator_to_array($response, false);

    $topLevelToolCalls = array_values(array_filter(
        $events,
        fn ($event) => $event instanceof ToolCallEvent && ! $event->isNested(),
    ));

    $middleEvents = array_values(array_filter(
        $events,
        fn ($event) => $event->isNested() && $event->parentToolCallId === 'call_001',
    ));

    $researchEvents = array_values(array_filter(
        $events,
        fn ($event) => $event->isNested() && $event->parentToolCallId === 'call_002',
    ));

    $topLevelToolResults = array_values(array_filter(
        $events,
        fn ($event) => $event instanceof ToolResultEvent && ! $event->isNested(),
    ));

    $nestedToolResults = array_values(array_filter(
        $events,
        fn ($event) => $event instanceof ToolResultEvent && $event->isNested(),
    ));

    expect($topLevelToolCalls)->toHaveCount(1)
        ->and($topLevelToolCalls[0]->toolCall->name)->toBe('streaming_middle_manager')
        ->and($middleEvents)->not->toBeEmpty()
        ->and($researchEvents)->not->toBeEmpty()
        ->and($middleEvents[0]->ancestorToolCallIds)->toBe(['call_001'])
        ->and($researchEvents[0]->ancestorToolCallIds)->toBe(['call_001', 'call_002'])
        ->and($topLevelToolResults)->toHaveCount(1)
        ->and($topLevelToolResults[0]->toolResult->result)->toBe('Middle done')
        ->and($nestedToolResults)->toHaveCount(1)
        ->and($nestedToolResults[0]->toolResult->result)->toBe('Deep research result')
        ->and($response->text)->toBe('Top done')
        ->and($response->events)->toHaveCount(count($events));
});

test('vercel protocol maps nested sub agent activity to data parts', function () {
    StreamingOrchestratorAgent::fake([
        new ToolCall('call_001', 'streaming_middle_manager', ['task' => 'Deep-dive on Laravel caching']),
        'Top done',
    ]);

    StreamingMiddleManagerAgent::fake([
        new ToolCall('call_002', 'streaming_research_agent', ['task' => 'Research Laravel caching internals']),
        'Middle done',
    ]);

    StreamingResearchAgent::fake(['Deep research result']);

    $response = (new StreamingOrchestratorAgent)->stream('Do a deep dive on Laravel caching');
    $events = iterator_to_array($response, false);

    $converter = new class('test', fn () => []) extends StreamableAgentResponse
    {
        public function convert(StreamEvent $event): array
        {
            return $this->nestedEventToVercelDataPart($event);
        }
    };

    $subAgentParts = array_map(
        fn (StreamEvent $event) => $converter->convert($event),
        array_values(array_filter($events, fn (StreamEvent $event) => $event->isNested())),
    );

    $topLevelToolResults = array_values(array_filter(
        $events,
        fn ($event) => $event instanceof ToolResultEvent && ! $event->isNested(),
    ));

    expect($subAgentParts)->not->toBeEmpty()
        ->and(array_column($subAgentParts, 'id'))->toContain('subagent:call_001')
        ->and(array_column($subAgentParts, 'id'))->toContain('subagent:call_001/call_002')
        ->and($subAgentParts[0]['type'])->toBe('data-subagent')
        ->and($topLevelToolResults)->toHaveCount(1)
        ->and($topLevelToolResults[0]->toolResult->id)->toBe('call_001');
});
