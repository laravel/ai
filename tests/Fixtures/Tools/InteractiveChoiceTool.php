<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Interactive;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\Response;
use Stringable;

class InteractiveChoiceTool implements Interactive, Tool
{
    public function description(): string
    {
        return 'Asks the user to choose.';
    }

    public function ask(Request $request): ?array
    {
        return ['question' => $request['question'], 'options' => $request['options']];
    }

    public function handle(Request $request): Stringable|string
    {
        $data = $request->validate(['answer' => 'required|string']);

        return new Response("chose: {$data['answer']}", ui: ['chosen' => $data['answer']]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
