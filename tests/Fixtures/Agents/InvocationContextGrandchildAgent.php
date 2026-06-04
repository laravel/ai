<?php

namespace Tests\Fixtures\Agents;

use Closure;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;

class InvocationContextGrandchildAgent implements Agent, CanActAsTool, HasMiddleware
{
    use Promptable;

    public function name(): string
    {
        return 'context_grandchild';
    }

    public function description(): string
    {
        return 'A leaf sub-agent used to verify deep invocation-context nesting.';
    }

    public function instructions(): string
    {
        return 'You are a leaf sub-agent used to verify deep context nesting.';
    }

    public function middleware(): array
    {
        return [
            new class
            {
                public function handle(AgentPrompt $prompt, Closure $next)
                {
                    $_SERVER['__testing.context-grandchild-prompt'] = $prompt;

                    return $next($prompt);
                }
            },
        ];
    }
}
