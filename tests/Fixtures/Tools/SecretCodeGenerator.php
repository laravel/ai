<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Tools\Request;

class SecretCodeGenerator implements HasProviderOptions, Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Returns the secret authorization code for the current session.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return 'ZEBRA-4417';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return ['defer_loading' => true];
    }
}
