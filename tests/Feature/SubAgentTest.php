<?php

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\AgentTool;
use Tests\Fixtures\Agents\DelegatingAgent;
use Tests\Fixtures\Agents\MiddleManagerAgent;
use Tests\Fixtures\Agents\OrchestratorAgent;
use Tests\Fixtures\Agents\RememberingDelegatingAgent;
use Tests\Fixtures\Agents\RememberingSubAgent;
use Tests\Fixtures\Agents\ResearchAgent;
use Tests\Fixtures\ConversationStores\InMemoryConversationStore;

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

test('sub-agent continues the parent conversation when both remember conversations', function () {
    $store = new InMemoryConversationStore;
    app()->instance(ConversationStore::class, $store);

    $user = (object) ['id' => 1];
    $conversationId = $store->storeConversation($user->id, 'Existing conversation');

    RememberingDelegatingAgent::fake([
        new ToolCall('call_123', 'remembering_sub_agent', ['task' => 'Summarize the request']),
        'Delegated.',
    ]);

    RememberingSubAgent::fake(['Sub-agent reply']);

    (new RememberingDelegatingAgent)
        ->continue($conversationId, $user)
        ->prompt('What is the status?');

    RememberingSubAgent::assertPrompted(function (AgentPrompt $prompt) use ($conversationId, $user) {
        return $prompt->agent->currentConversation() === $conversationId
            && $prompt->agent->conversationParticipant() === $user;
    });
});

test('sub-agent stays isolated when the parent has no active conversation', function () {
    $store = new InMemoryConversationStore;
    app()->instance(ConversationStore::class, $store);

    $user = (object) ['id' => 1];

    RememberingDelegatingAgent::fake([
        new ToolCall('call_123', 'remembering_sub_agent', ['task' => 'Do the work']),
        'Delegated.',
    ]);

    RememberingSubAgent::fake(['Sub-agent reply']);

    // forUser() starts a fresh conversation whose id is only persisted after the run,
    // so there is nothing to share while the sub-agent is being delegated to.
    (new RememberingDelegatingAgent)
        ->forUser($user)
        ->prompt('Start');

    RememberingSubAgent::assertPrompted(function (AgentPrompt $prompt) {
        return $prompt->agent->currentConversation() === null;
    });
});
