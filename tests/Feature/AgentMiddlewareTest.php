<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Tests\Feature\Agents\AssistantAgent;

test('agent middleware is invoked', function () {
    AssistantAgent::fake([
        'Fake response',
    ]);

    $response = (new AssistantAgent)
        ->withMiddleware([middleware()])
        ->prompt('Test prompt');

    expect($response->text)->toEqual('Fake response');
    expect($_SERVER['__testing.middleware-prompt'])->toBeInstanceOf(AgentPrompt::class);

    unset($_SERVER['__testing.middleware-prompt']);
});

test('agent middleware is invoked when streaming', function () {
    AssistantAgent::fake([
        'Fake response',
    ]);

    $response = (new AssistantAgent)
        ->withMiddleware([middleware()])
        ->stream('Test prompt');

    $response
        ->each(fn () => true)
        ->then(function (StreamedAgentResponse $response) {
            $_SERVER['__testing.text'] = $response->text;
        });

    expect($_SERVER['__testing.text'])->toEqual('Fake response');
    expect($_SERVER['__testing.middleware-prompt'])->toBeInstanceOf(AgentPrompt::class);

    unset($_SERVER['__testing.text']);
    unset($_SERVER['__testing.middleware-prompt']);
});

test('agent prompted event receives prompt when middleware short circuits', function () {
    Event::fake();

    AssistantAgent::fake([
        'Fake response',
    ]);

    (new AssistantAgent)
        ->withMiddleware([shortCircuitingMiddleware()])
        ->prompt('Test prompt');

    Event::assertDispatched(AgentPrompted::class, function (AgentPrompted $event) {
        return $event->prompt instanceof AgentPrompt
            && $event->prompt->prompt === 'Test prompt';
    });
});

function shortCircuitingMiddleware(): object
{
    return new class
    {
        public function handle(AgentPrompt $prompt, Closure $next)
        {
            return new AgentResponse(
                'test-invocation-id',
                'Short-circuited response',
                new Usage,
                new Meta,
            );
        }
    };
}

function middleware(): object
{
    return new class
    {
        public function handle(AgentPrompt $prompt, Closure $next)
        {
            $_SERVER['__testing.middleware-prompt'] = $prompt;

            return $next($prompt);
        }
    };
}
