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

        $response = (new AssistantAgent)->withMiddleware([
            new class
            {
                public function handle(AgentPrompt $prompt, Closure $next)
                {
                    $_SERVER['__testing.prompt'] = $prompt;

                    return $next($prompt);
                }
            },
        ])->prompt('Test prompt');

        $this->assertEquals('Fake response', $response->text);
        $this->assertInstanceOf(AgentPrompt::class, $_SERVER['__testing.prompt']);

        unset($_SERVER['__testing.prompt']);
    }

    public function test_agent_middleware_is_invoked_when_streaming(): void
    {
        AssistantAgent::fake([
            new AgentResponse((string) Str::uuid7(), 'Fake response', new Usage, new Meta),
        ]);

        $response = (new AssistantAgent)->withMiddleware([
            new class
            {
                public function handle(AgentPrompt $prompt, Closure $next)
                {
                    $_SERVER['__testing.prompt'] = $prompt;

                    return $next($prompt);
                }
            },
        ])->stream('Test prompt');

        $response
            ->each(fn () => true)
            ->then(function (StreamableAgentResponse $response) {
                $this->assertEquals('Fake response', $response->text);
            });

        $this->assertInstanceOf(AgentPrompt::class, $_SERVER['__testing.prompt']);

        unset($_SERVER['__testing.prompt']);
    }
}
