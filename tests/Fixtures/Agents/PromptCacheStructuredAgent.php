<?php

namespace Tests\Fixtures\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[CacheToolDefinitions]
class PromptCacheStructuredAgent implements Agent, HasStructuredOutput
{
    use Promptable;

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
}
