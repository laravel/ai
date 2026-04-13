<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Promptable;

class AssistantAgent implements Agent, HasMiddleware
{
    use Promptable;

    protected $middleware = [];

    public function instructions(): string
    {
        return 'You are a helpful assistant that responds extremely concisely to all queries.';
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
