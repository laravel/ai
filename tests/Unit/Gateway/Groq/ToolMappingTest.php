<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionTools;
use Laravel\Ai\Tools\Request;

test('tool parameters are not wrapped in schema definition', function () {
    $mapper = new class
    {
        use MapsChatCompletionTools;

        public function map(array $tools): array
        {
            return array_map(fn ($tool) => $this->mapTool($tool), $tools);
        }
    };

    $tool = new class implements Tool
    {
        public function description(): string
        {
            return 'Creates a new lead';
        }

        public function handle(Request $request): string
        {
            return 'done';
        }

        public function schema(JsonSchema $schema): array
        {
            return [
                'name' => $schema->string()->required()->description('full customer name'),
                'email' => $schema->string()->nullable(),
                'phone_number' => $schema->string()->required()->description("customer's phone number"),
            ];
        }
    };

    $mapped = $mapper->map([$tool]);

    $parameters = $mapped[0]['function']['parameters'];

    expect($parameters['properties'] ?? [])->not->toHaveKey('schema_definition')
        ->and($parameters['required'] ?? [])->not->toContain('schema_definition')
        ->and($parameters['properties'])->toHaveKeys(['name', 'email', 'phone_number'])
        ->and($parameters['required'])->toContain('name')
        ->toContain('phone_number')
        ->and($parameters['type'])->toEqual('object')
        ->and($parameters['additionalProperties'])->toBeFalse();
});

test('tool with empty schema includes parameters', function () {
    $mapper = new class
    {
        use MapsChatCompletionTools;

        public function map(array $tools): array
        {
            return array_map(fn ($tool) => $this->mapTool($tool), $tools);
        }
    };

    $tool = new class implements Tool
    {
        public function description(): string
        {
            return 'A tool with no parameters';
        }

        public function handle(Request $request): string
        {
            return 'done';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $mapped = $mapper->map([$tool]);

    $function = $mapped[0]['function'];

    expect($function)->toHaveKey('parameters')
        ->and($function['parameters'])->toMatchArray(['type' => 'object', 'required' => []])
        ->and($function['parameters']['additionalProperties'])->toBeFalse();
});
