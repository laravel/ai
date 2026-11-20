<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Tools\NonStrictTool;

function bedrockStrictOrderTool(): Tool
{
    return new #[Strict] class implements Tool
    {
        public function description(): string
        {
            return 'Orders a number of items.';
        }

        public function handle(Request $request): string
        {
            return 'ok';
        }

        public function schema(JsonSchema $schema): array
        {
            return [
                'quantity' => $schema->integer()->min(1)->required(),
                'note' => $schema->string(),
            ];
        }
    };
}

test('tool with Strict attribute sends strict true', function (): void {
    $parameters = $this->capturedConverseParameters(tools: [bedrockStrictOrderTool()]);

    $toolSpec = $parameters['toolConfig']['tools'][0]['toolSpec'];

    expect($toolSpec['strict'])->toBeTrue()
        ->and($toolSpec['inputSchema']['json']['additionalProperties'])->toBeFalse()
        ->and($toolSpec['inputSchema']['json']['required'])->toBe(['quantity']);
});

test('tool without Strict attribute omits strict', function (): void {
    $parameters = $this->capturedConverseParameters(tools: [new NonStrictTool]);

    expect($parameters['toolConfig']['tools'][0]['toolSpec'])->not->toHaveKey('strict');
});

test('strict tool schemas drop keywords bedrock strict mode rejects', function (): void {
    $parameters = $this->capturedConverseParameters(tools: [bedrockStrictOrderTool()]);

    $quantity = $parameters['toolConfig']['tools'][0]['toolSpec']['inputSchema']['json']['properties']['quantity'];

    expect($quantity)->not->toHaveKey('minimum')
        ->and($quantity['description'])->toContain('Must be at least 1.');
});

test('non-strict tool schemas keep their keywords', function (): void {
    $tool = new class implements Tool
    {
        public function description(): string
        {
            return 'Orders a number of items.';
        }

        public function handle(Request $request): string
        {
            return 'ok';
        }

        public function schema(JsonSchema $schema): array
        {
            return ['quantity' => $schema->integer()->min(1)->required()];
        }
    };

    $parameters = $this->capturedConverseParameters(tools: [$tool]);

    expect($parameters['toolConfig']['tools'][0]['toolSpec']['inputSchema']['json']['properties']['quantity']['minimum'])->toBe(1);
});
