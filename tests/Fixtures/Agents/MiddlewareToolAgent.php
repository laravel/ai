<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\NamedTool;

class MiddlewareToolAgent implements Agent, HasMiddleware, HasTools
{
    use Promptable;

    protected $middleware = [];

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function tools(): iterable
    {
        return [
            new NamedTool,
        ];
    }

    public function middleware(): array
    {
        return $this->middleware;
    }

    public function withMiddleware(array $middleware): self
    {
        $this->middleware = $middleware;

        return $this;
    }
}
