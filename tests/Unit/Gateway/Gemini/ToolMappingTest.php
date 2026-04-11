<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Gemini\Concerns\MapsTools;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Tools\Request;

test('nullable parameters are converted to OpenAPI-style nullable format', function () {
    $mapper = new class
    {
        use MapsTools;

        public function map(array $tools): array
        {
            $declarations = [];

            foreach ($tools as $tool) {
                $declarations[] = $this->mapTool($tool);
            }

            return $declarations;
        }
    };

    $tool = new class implements Tool
    {
        public function description(): string
        {
            return 'A tool with nullable parameters';
        }

        public function handle(Request $request): string
        {
            return 'done';
        }

        public function schema(JsonSchema $schema): array
        {
            return [
                'name' => $schema->string()->required(),
                'email' => $schema->string()->nullable()->required(),
                'age' => $schema->integer()->nullable(),
            ];
        }
    };

    $mapped = $mapper->map([$tool]);

    $props = $mapped[0]['parameters']['properties'];

    // Non-nullable: plain string type, no nullable flag
    expect($props['name']['type'])->toBe('string')
        ->and($props['name'])->not->toHaveKey('nullable');

    // Nullable string: single type with nullable: true
    expect($props['email']['type'])->toBe('string')
        ->and($props['email']['nullable'])->toBeTrue();

    // Nullable integer: single type with nullable: true
    expect($props['age']['type'])->toBe('integer')
        ->and($props['age']['nullable'])->toBeTrue();
});

