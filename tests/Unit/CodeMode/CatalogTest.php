<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\CodeMode\Catalog;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

function catalogTool(string $name, string $description, Closure $schemaDefinition): Tool
{
    return new class($name, $description, $schemaDefinition) implements Tool
    {
        public function __construct(
            protected string $toolName,
            protected string $toolDescription,
            protected Closure $schemaDefinition,
        ) {}

        public function name(): string
        {
            return $this->toolName;
        }

        public function description(): string
        {
            return $this->toolDescription;
        }

        public function handle(Request $request): string
        {
            return 'ok';
        }

        public function schema(JsonSchema $schema): array
        {
            return ($this->schemaDefinition)($schema);
        }
    };
}

test('catalog search preserves complete nested JSON schemas', function (): void {
    $tool = catalogTool('create_user', 'Create a user.', fn (JsonSchema $schema): array => [
        'role' => $schema->string()->enum(['admin', 'editor'])->default('editor'),
        'profile' => $schema->object([
            'display_name' => $schema->string()->min(2)->max(50)->required(),
        ])->required(),
        'tags' => $schema->array()->items($schema->string()->pattern('^[a-z]+$')),
    ]);

    $entry = (new Catalog(['users.create' => $tool]))->search('', 1)[0];
    $properties = $entry['schema']['properties'];

    expect($properties['role']['enum'])->toBe(['admin', 'editor'])
        ->and($properties['role']['default'])->toBe('editor')
        ->and($properties['profile']['properties']['display_name']['minLength'])->toBe(2)
        ->and($properties['profile']['properties']['display_name']['maxLength'])->toBe(50)
        ->and($properties['tags']['items']['pattern'])->toBe('^[a-z]+$');
});

test('catalog ranking indexes parameter names and descriptions independently', function (): void {
    $catalog = new Catalog([
        'alpha' => catalogTool('alpha', 'A generic action.', fn (JsonSchema $schema): array => [
            'destination_city' => $schema->string()->description('The arrival location.'),
        ]),
        'beta' => catalogTool('beta', 'A generic action.', fn (JsonSchema $schema): array => [
            'identifier' => $schema->string(),
        ]),
        'gamma' => catalogTool('gamma', 'Deliver a parcel overnight.', fn (): array => []),
    ]);

    expect(array_column($catalog->search('destination city'), 'path'))->toBe(['alpha'])
        ->and(array_column($catalog->search('overnight'), 'path'))->toBe(['gamma']);
});

test('catalog search tokenizes Unicode descriptions', function (): void {
    $catalog = new Catalog([
        'weather' => catalogTool('weather', '東京の天気を調べる', fn (): array => []),
        'mail' => catalogTool('mail', 'メールを送る', fn (): array => []),
    ]);

    expect(array_column($catalog->search('天気'), 'path'))->toBe(['weather']);
});
