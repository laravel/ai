<?php

namespace Tests\Fixtures\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class StructuredMiddlewareAgent implements Agent, HasMiddleware, HasStructuredOutput
{
    use Promptable;

    protected $middleware = [];

    public function instructions(): string
    {
        return 'You are a helpful assistant that uses structured output.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'symbol' => $schema->string()->required(),
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
