<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Agents\ResearchAgent;

class AgentCallingTool implements Tool
{
    public function description(): string
    {
        return 'Delegates to a research agent from within the tool handler.';
    }

    public function handle(Request $request): string
    {
        return ResearchAgent::make()->prompt('Research Laravel')->text;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
