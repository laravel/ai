<?php

namespace Tests\Feature\Agents;

use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\SubAgent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;

class CalledTrackingSubAgent implements HasMiddleware, SubAgent
{
    use Promptable;

    public static array $tasks = [];

    public function name(): string
    {
        return 'called_tracking_subagent';
    }

    public function instructions(): string
    {
        return 'You are a delegated executor that echoes completed tasks.';
    }

    public function middleware(): array
    {
        return [function (AgentPrompt $prompt, $next) {
            static::$tasks[] = $prompt->prompt;

            return $next($prompt);
        }];
    }

    public function description(): string
    {
        return 'Executes delegated tasks and returns completion text.';
    }
}
