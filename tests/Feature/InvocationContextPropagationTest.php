<?php

use Laravel\Ai\InvocationContext;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\InvocationContextChildAgent;
use Tests\Fixtures\Agents\InvocationContextGrandchildAgent;
use Tests\Fixtures\Agents\InvocationContextParentAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

afterEach(function () {
    InvocationContext::flush();

    unset(
        $_SERVER['__testing.context-root-prompt'],
        $_SERVER['__testing.context-child-prompt'],
        $_SERVER['__testing.context-child-response'],
        $_SERVER['__testing.context-grandchild-prompt'],
    );
});

test('a top-level prompt is stamped as a root invocation', function () {
    AssistantAgent::fake(['Done']);

    $response = (new AssistantAgent)
        ->withMiddleware([new class
        {
            public function handle(AgentPrompt $prompt, Closure $next)
            {
                $_SERVER['__testing.context-root-prompt'] = $prompt;

                return $next($prompt);
            }
        }])
        ->prompt('Hello');

    $prompt = $_SERVER['__testing.context-root-prompt'];

    expect($prompt->invocationId)->not->toBeNull()
        ->and($prompt->parentInvocationId)->toBeNull()
        ->and($prompt->rootInvocationId)->toBe($prompt->invocationId)
        ->and($response->parentInvocationId)->toBeNull()
        ->and($response->rootInvocationId)->toBe($response->invocationId);
});

test('a sub-agent invoked as a tool inherits the parent trace and root', function () {
    InvocationContextChildAgent::fake(['Child done']);

    InvocationContextParentAgent::fake([
        new ToolCall('call-1', 'context_child', ['task' => 'do research']),
        'Parent done',
    ]);

    $response = (new InvocationContextParentAgent)->prompt('Delegate this');

    $childPrompt = $_SERVER['__testing.context-child-prompt'] ?? null;

    expect($childPrompt)->toBeInstanceOf(AgentPrompt::class)
        ->and($childPrompt->invocationId)->not->toBeNull()
        ->and($childPrompt->invocationId)->not->toBe($response->invocationId)
        ->and($childPrompt->parentInvocationId)->toBe($response->invocationId)
        ->and($childPrompt->rootInvocationId)->toBe($response->invocationId);
});

test('a sub-agent response is stamped with the parent trace and root', function () {
    InvocationContextChildAgent::fake(['Child done']);

    InvocationContextParentAgent::fake([
        new ToolCall('call-1', 'context_child', ['task' => 'do research']),
        'Parent done',
    ]);

    $response = (new InvocationContextParentAgent)->prompt('Delegate this');

    $childResponse = $_SERVER['__testing.context-child-response'] ?? null;

    expect($childResponse->parentInvocationId)->toBe($response->invocationId)
        ->and($childResponse->rootInvocationId)->toBe($response->invocationId);
});

test('a deeply nested sub-agent chain shares one root and chains its parents', function () {
    InvocationContextGrandchildAgent::fake(['Grandchild done']);

    InvocationContextChildAgent::fake([
        new ToolCall('call-2', 'context_grandchild', ['task' => 'deep work']),
        'Child done',
    ]);

    InvocationContextParentAgent::fake([
        new ToolCall('call-1', 'context_child', ['task' => 'do research']),
        'Parent done',
    ]);

    $response = (new InvocationContextParentAgent)->prompt('Delegate this');

    $childPrompt = $_SERVER['__testing.context-child-prompt'];
    $grandchildPrompt = $_SERVER['__testing.context-grandchild-prompt'];

    expect($childPrompt->parentInvocationId)->toBe($response->invocationId)
        ->and($childPrompt->rootInvocationId)->toBe($response->invocationId)
        ->and($grandchildPrompt->invocationId)->not->toBe($childPrompt->invocationId)
        ->and($grandchildPrompt->parentInvocationId)->toBe($childPrompt->invocationId)
        ->and($grandchildPrompt->rootInvocationId)->toBe($response->invocationId);
});

test('a structured-output response is stamped with the invocation context', function () {
    ToolUsingAgent::fake([['number' => 7]]);

    $response = (new ToolUsingAgent)->prompt('Give me a number');

    expect($response)->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($response->parentInvocationId)->toBeNull()
        ->and($response->rootInvocationId)->toBe($response->invocationId);
});

test('a streamed top-level response is stamped as a root invocation', function () {
    AssistantAgent::fake(['Streamed response']);

    $response = (new AssistantAgent)
        ->withMiddleware([new class
        {
            public function handle(AgentPrompt $prompt, Closure $next)
            {
                $_SERVER['__testing.context-root-prompt'] = $prompt;

                return $next($prompt);
            }
        }])
        ->stream('Hello');

    $streamed = null;
    $response->then(function (StreamedAgentResponse $r) use (&$streamed) {
        $streamed = $r;
    });

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    $prompt = $_SERVER['__testing.context-root-prompt'];

    expect($prompt->parentInvocationId)->toBeNull()
        ->and($prompt->rootInvocationId)->toBe($prompt->invocationId)
        ->and($streamed)->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($streamed->parentInvocationId)->toBeNull()
        ->and($streamed->rootInvocationId)->toBe($streamed->invocationId);
});

test('the invocation context is active while a stream is consumed', function () {
    AssistantAgent::fake(['one two three']);

    $response = (new AssistantAgent)->stream('Hello');

    $idsDuringStream = [];
    foreach ($response as $event) {
        $idsDuringStream[] = InvocationContext::current()?->id;
    }

    expect($idsDuringStream)->not->toBeEmpty()
        ->and(collect($idsDuringStream)->unique()->values()->all())->toBe([$response->invocationId])
        ->and(InvocationContext::current())->toBeNull();
});
