<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\Response;
use Stringable;

class ReceiptTool implements Tool
{
    public function description(): string
    {
        return 'Shows a receipt.';
    }

    public function handle(Request $request): Stringable|string
    {
        return new Response("Total: {$request['total']}", ui: ['total' => $request['total']]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
