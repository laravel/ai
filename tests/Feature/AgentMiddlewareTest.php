<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\AgentPrompt;
use Laravel\Ai\Data\Meta;
use Laravel\Ai\Data\Usage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Tests\Feature\Agents\AssistantAgent;
use Tests\TestCase;

class AgentMiddlewareTest extends TestCase
{
    public function test_agent_middleware_is_invoked(): void
    {
        AssistantAgent::fake([
            new AgentResponse((string) Str::uuid7(), 'Fake response', new Usage, new Meta),
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
            new AgentResponse((string) Str::uuid7(), 'Fake response', new Usage, new Meta),
        ]);

        $response = (new AssistantAgent)
            ->withMiddleware([$this->middleware()])
            ->stream('Test prompt');

        $response
            ->each(fn () => true)
            ->then(function (StreamableAgentResponse $response) {
                $_SERVER['__testing.text'] = $response->text;
            });

        $this->assertEquals('Fake response', $_SERVER['__testing.text']);
        $this->assertInstanceOf(AgentPrompt::class, $_SERVER['__testing.middleware-prompt']);

        unset($_SERVER['__testing.text']);
        unset($_SERVER['__testing.middleware-prompt']);
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
