<?php

namespace Tests\Feature\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class StructuredAgent implements Agent, HasMiddleware, HasStructuredOutput
{
    use Promptable;

    protected $middleware = [];

    public function middleware(): array
    {
        return $this->middleware;
    }

    public function withMiddleware(array $middleware): self
    {
        $this->middleware = $middleware;

        return $this;
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that uses structured output and knows about periodic table element symbols.';
    }

    /**
     * Get the structured output's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'symbol' => $schema->string()->required(),
        ];
    }
}
