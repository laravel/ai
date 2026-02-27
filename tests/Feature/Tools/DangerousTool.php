<?php

namespace Tests\Feature\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class DangerousTool implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'This tool performs a dangerous operation that requires human approval.';
    }

    /**
     * Determine if the tool requires approval before execution.
     */
    public function requiresApproval(): bool
    {
        return true;
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return "Deleted files for user {$request['user_id']}";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()->description('The user ID')->required(),
        ];
    }
}
