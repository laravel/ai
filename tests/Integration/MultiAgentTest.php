<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\AgentTool;
use Tests\Fixtures\Agents\DelegatingAgent;
use Tests\Fixtures\Agents\ResearchAgent;

test('a parent agent delegates to a sub-agent and the sub-agent runs end-to-end', function (string $provider, string $apiKey, string $model): void {
    requiresApiKey($apiKey);

    config(['ai.default' => $provider]);

    Event::fake();

    $response = (new DelegatingAgent)->prompt(
        'Use the research_agent tool to answer: "What is Laravel?" Forward the user question verbatim as the task.',
        provider: $provider,
        model: $model,
    );

    $researchToolCall = $response->toolCalls->firstWhere('name', 'research_agent');

    expect($researchToolCall)->not->toBeNull()
        ->and($researchToolCall->arguments)->toHaveKey('task')
        ->and($researchToolCall->arguments['task'])->toBeString()->not->toBeEmpty();

    Event::assertDispatched(InvokingTool::class, fn ($event): bool => $event->tool instanceof AgentTool
        && $event->tool->agent() instanceof ResearchAgent
        && ($event->arguments['task'] ?? null) === $researchToolCall->arguments['task']);

    $researchPrompted = null;

    Event::assertDispatched(AgentPrompted::class, function ($event) use (&$researchPrompted): bool {
        if ($event->prompt->agent instanceof ResearchAgent) {
            $researchPrompted = $event;

            return true;
        }

        return false;
    });

    expect($researchPrompted)->not->toBeNull()
        ->and($researchPrompted->prompt->prompt)->toBe($researchToolCall->arguments['task'])
        ->and($researchPrompted->response->text)->toBeString()->not->toBeEmpty();

    Event::assertDispatched(PromptingAgent::class, fn ($event): bool => $event->prompt->agent instanceof ResearchAgent);

    $researchToolResult = $response->toolResults->firstWhere('name', 'research_agent');

    expect($researchToolResult)->not->toBeNull()
        ->and($researchToolResult->result)->toBe($researchPrompted->response->text);

    Event::assertDispatched(ToolInvoked::class, fn ($event): bool => $event->tool instanceof AgentTool
        && $event->tool->agent() instanceof ResearchAgent
        && $event->result === $researchPrompted->response->text);
})->with('agent-providers');
