<?php

namespace Tests\Feature\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class NullableStructuredAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that knows about periodic table elements and their properties.';
    }

    /**
     * Get the structured output's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'symbol' => $schema->string()->required(),
            'meltingPoint' => $schema->number()->nullable()->required(),
            'boilingPoint' => $schema->number()->nullable()->required(),
        ];
    }
}
