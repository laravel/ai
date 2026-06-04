<?php

use Illuminate\Support\Facades\Queue;
use Laravel\Ai\InvocationContext;
use Laravel\Ai\Jobs\InvokeAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Fixtures\Agents\AssistantAgent;

afterEach(function () {
    InvocationContext::flush();

    unset($_SERVER['__testing.queued-prompt']);
});

test('queue() forwards the active invocation context to the job', function () {
    Queue::fake();

    InvocationContext::run(InvocationContext::root('parent-inv'), function () {
        (new AssistantAgent)->queue('Do it');
    });

    Queue::assertPushed(InvokeAgent::class, function (InvokeAgent $job) {
        return $job->parentInvocationId === 'parent-inv'
            && $job->rootInvocationId === 'parent-inv';
    });
});

test('queue() outside any invocation forwards no lineage', function () {
    Queue::fake();

    (new AssistantAgent)->queue('Do it');

    Queue::assertPushed(InvokeAgent::class, function (InvokeAgent $job) {
        return $job->parentInvocationId === null
            && $job->rootInvocationId === null;
    });
});

test('a queued job re-establishes the dispatching context so its agent nests beneath it', function () {
    AssistantAgent::fake(['Done']);

    $agent = (new AssistantAgent)->withMiddleware([new class
    {
        public function handle(AgentPrompt $prompt, Closure $next)
        {
            $_SERVER['__testing.queued-prompt'] = $prompt;

            return $next($prompt);
        }
    }]);

    (new InvokeAgent($agent, 'Do it', [], null, null, 'parent-inv', 'root-inv'))->handle();

    $prompt = $_SERVER['__testing.queued-prompt'];

    expect($prompt->invocationId)->not->toBe('parent-inv')
        ->and($prompt->parentInvocationId)->toBe('parent-inv')
        ->and($prompt->rootInvocationId)->toBe('root-inv');
});

test('a queued job with no lineage runs its agent as a root', function () {
    AssistantAgent::fake(['Done']);

    $agent = (new AssistantAgent)->withMiddleware([new class
    {
        public function handle(AgentPrompt $prompt, Closure $next)
        {
            $_SERVER['__testing.queued-prompt'] = $prompt;

            return $next($prompt);
        }
    }]);

    (new InvokeAgent($agent, 'Do it'))->handle();

    $prompt = $_SERVER['__testing.queued-prompt'];

    expect($prompt->parentInvocationId)->toBeNull()
        ->and($prompt->rootInvocationId)->toBe($prompt->invocationId);
});
