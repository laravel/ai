<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Tests\Feature\Agents\AssistantAgent;
use Tests\TestCase;

class AgentMiddlewareTest extends TestCase
{
    public function test_agent_middleware_is_invoked(): void
    {
        AssistantAgent::fake([
            'Fake response',
        ]);

        $response = (new AssistantAgent)
            ->withMiddleware([$this->middleware()])
            ->prompt('Test prompt');

        $this->assertEquals('Fake response', $response->text);
        $this->assertInstanceOf(AgentPrompt::class, $_SERVER['__testing.middleware-prompt']);

        unset($_SERVER['__testing.middleware-prompt']);
    }

    public function test_agent_middleware_is_invoked_when_streaming(): void
    {
        AssistantAgent::fake([
            'Fake response',
        ]);

        $response = (new AssistantAgent)
            ->withMiddleware([$this->middleware()])
            ->stream('Test prompt');

        $response
            ->each(fn () => true)
            ->then(function (StreamedAgentResponse $response) {
                $_SERVER['__testing.text'] = $response->text;
            });

        $this->assertEquals('Fake response', $_SERVER['__testing.text']);
        $this->assertInstanceOf(AgentPrompt::class, $_SERVER['__testing.middleware-prompt']);

        unset($_SERVER['__testing.text']);
        unset($_SERVER['__testing.middleware-prompt']);
    }

    public function test_agent_prompted_event_receives_prompt_when_middleware_short_circuits(): void
    {
        Event::fake();

        AssistantAgent::fake([
            'Fake response',
        ]);

        (new AssistantAgent)
            ->withMiddleware([$this->shortCircuitingMiddleware()])
            ->prompt('Test prompt');

        Event::assertDispatched(AgentPrompted::class, function (AgentPrompted $event) {
            return $event->prompt instanceof AgentPrompt
                && $event->prompt->prompt === 'Test prompt';
        });
    }

    protected function shortCircuitingMiddleware(): object
    {
        return new class
        {
            public function handle(AgentPrompt $prompt, Closure $next)
            {
                return new AgentResponse(
                    'test-invocation-id',
                    'Short-circuited response',
                    new \Laravel\Ai\Responses\Data\Usage,
                    new \Laravel\Ai\Responses\Data\Meta,
                );
            }
        };
    }

    protected function middleware(): object
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
}
