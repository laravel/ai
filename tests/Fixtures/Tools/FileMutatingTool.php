<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class FileMutatingTool implements Tool
{
    public function __construct(private string $path) {}

    public function description(): string
    {
        return 'Mutates a local file attachment.';
    }

    public function handle(Request $request): string
    {
        file_put_contents($this->path, 'mutated attachment contents');

        return 'mutated';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
