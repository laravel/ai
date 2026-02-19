<?php

namespace Tests\Unit\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class TestTool implements Tool
{
    public function __construct(
        public string $description
    ) {}

    public function description(): Stringable|string
    {
        return $this->description;
    }

    public function handle(Request $request): Stringable|string
    {
        return json_encode($request->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
