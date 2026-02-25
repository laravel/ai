<?php

namespace Tests\Feature\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ConditionalApprovalTool implements Tool
{
    public function __construct(public bool $shouldRequireApproval = false) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'This tool conditionally requires approval.';
    }

    /**
     * Determine if the tool requires approval before execution.
     */
    public function requiresApproval(): bool
    {
        return $this->shouldRequireApproval;
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return 'Conditional tool executed';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
