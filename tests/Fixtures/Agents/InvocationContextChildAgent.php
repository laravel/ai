<?php

namespace Tests\Fixtures\Agents;

use Closure;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;

class InvocationContextChildAgent implements Agent, CanActAsTool, HasMiddleware, HasTools
{
    use Promptable;

    public function name(): string
    {
        return 'context_child';
    }

    public function description(): string
    {
        return 'Records the invocation context it receives so it can be asserted.';
    }

    public function instructions(): string
    {
        return 'You are a sub-agent used to verify invocation context propagation.';
    }

    public function tools(): iterable
    {
        return [
            new InvocationContextGrandchildAgent,
        ];
    }

    public function middleware(): array
    {
        return [
            new class
            {
                public function handle(AgentPrompt $prompt, Closure $next)
                {
                    $_SERVER['__testing.context-child-prompt'] = $prompt;

                    $response = $next($prompt);

                    $_SERVER['__testing.context-child-response'] = $response;

                    return $response;
                }
            },
        ];
    }
}
