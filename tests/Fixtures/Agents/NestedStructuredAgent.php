<?php

namespace Tests\Fixtures\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class NestedStructuredAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that knows about periodic table elements.';
    }

    /**
     * Get the structured output's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'elements' => $schema->array()->required()->items(
                $schema->object([
                    'atomicNumber' => $schema->integer()->required(),
                    'symbol' => $schema->string()->required(),
                ])
            ),
        ];
    }
}
