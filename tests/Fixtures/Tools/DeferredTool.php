<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Tools\Request;

class DeferredTool implements HasProviderOptions, Tool
{
    /**
     * @param  array<string, mixed>  $providerOptions
     */
    public function __construct(protected array $providerOptions = ['defer_loading' => true])
    {
        //
    }

    public function description(): string
    {
        return 'A deferred tool whose definition is loaded on demand.';
    }

    public function handle(Request $request): string
    {
        return 'done';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $this->providerOptions;
    }
}
