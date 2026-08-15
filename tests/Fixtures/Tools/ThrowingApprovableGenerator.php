<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ThrowingApprovableGenerator implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Generates a number, but requires human approval first and always fails.';
    }

    public function handle(Request $request): string
    {
        throw new \Exception('Forced to throw exception.');
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
