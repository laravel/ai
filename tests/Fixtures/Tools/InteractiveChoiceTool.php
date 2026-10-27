<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\RequiresInput;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\Response;
use Stringable;

class InteractiveChoiceTool implements RequiresInput, Tool
{
    public function description(): string
    {
        return 'Asks the user to choose.';
    }

    public function requiresInput(JsonSchema $schema, Request $request): array
    {
        return ['answer' => $schema->string()->enum($request['options'] ?? [])->required()];
    }

    public function handle(Request $request): Stringable|string
    {
        return new Response("chose: {$request['answer']}", data: ['chosen' => $request['answer']]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
