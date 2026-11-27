<?php

namespace Tests\Fixtures\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Strict]
class RecursiveStructuredAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You turn formulas into expression trees.';
    }

    /**
     * Get the structured output's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'expression' => $schema->object([
                'operation' => $schema->string()->nullable()->required(),
                'children' => $schema->array()->items($schema->ref('expression'))->nullable()->required(),
            ])->define('expression')->required(),
        ];
    }
}
