<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApproval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ApprovableNumberGenerator implements Approvable, Tool
{
    use InteractsWithApproval;

    public static int $invocations = 0;

    public function description(): string
    {
        return 'Generates a number, but requires human approval first.';
    }

    public function handle(Request $request): string
    {
        static::$invocations++;

        return '72019';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
