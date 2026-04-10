<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Xai\Concerns\MapsTools;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Tools\Request;

test('tool parameters are not wrapped in schema definition', function () {
    $mapper = new class
    {
        use MapsTools;

        public function map(array $tools, Provider $provider): array
        {
            return $this->mapTools($tools, $provider);
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

    $provider = $this->createMock(Provider::class);

    $mapped = $mapper->map([$tool], $provider);

    $parameters = $mapped[0]['parameters'];

    expect($parameters['properties'] ?? [])->not->toHaveKey('schema_definition');
    expect($parameters['required'] ?? [])->not->toContain('schema_definition');
    expect($parameters['properties'])->toHaveKey('name');
    expect($parameters['properties'])->toHaveKey('email');
    expect($parameters['properties'])->toHaveKey('phone_number');
    expect($parameters['required'])->toContain('name');
    expect($parameters['required'])->toContain('phone_number');
    expect($parameters['type'])->toEqual('object');
    expect($parameters['additionalProperties'])->toBeFalse();
});

test('tool with empty schema includes parameters', function () {
    $mapper = new class
    {
        use MapsTools;

        public function map(array $tools, Provider $provider): array
        {
            return $this->mapTools($tools, $provider);
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

    $provider = $this->createMock(Provider::class);

    $mapped = $mapper->map([$tool], $provider);

    expect($mapped[0])->toHaveKey('parameters');
    expect($mapped[0]['parameters']['type'])->toEqual('object');
    expect($mapped[0]['parameters']['required'])->toEqual([]);
    expect($mapped[0]['parameters']['additionalProperties'])->toBeFalse();
});
