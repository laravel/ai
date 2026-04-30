<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasRawToolSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Concerns\ResolvesToolMetadata;
use Laravel\Ai\Tools\Request;

function metadataResolver(): object
{
    return new class
    {
        use ResolvesToolMetadata {
            toolHasRawSchema as public;
            toolSchemaArray as public;
            normalizeRawToolSchema as public;
        }
    };
}

class ResolvesToolMetadataPlainTool implements Tool
{
    public function description(): string
    {
        return 'plain';
    }

    public function handle(Request $request): string
    {
        return '';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

test('toolHasRawSchema reflects the HasRawToolSchema interface', function () {
    $resolver = metadataResolver();

    expect($resolver->toolHasRawSchema(new ResolvesToolMetadataPlainTool))->toBeFalse();

    $rawTool = new class implements Tool, HasRawToolSchema
    {
        public function rawSchema(): array
        {
            return ['type' => 'object'];
        }

        public function description(): string
        {
            return '';
        }

        public function handle(Request $request): string
        {
            return '';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    expect($resolver->toolHasRawSchema($rawTool))->toBeTrue();
});

test('toolSchemaArray returns normalized raw schema when available', function () {
    $tool = new class implements Tool, HasRawToolSchema
    {
        public function rawSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'required' => ['name'],
            ];
        }

        public function description(): string
        {
            return '';
        }

        public function handle(Request $request): string
        {
            return '';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $schema = metadataResolver()->toolSchemaArray($tool);

    expect($schema['type'])->toBe('object')
        ->and($schema['properties'])->toBe(['name' => ['type' => 'string']])
        ->and($schema['required'])->toBe(['name']);
});

test('normalizeRawToolSchema fills in missing required keys', function () {
    $schema = metadataResolver()->normalizeRawToolSchema([]);

    expect($schema['type'])->toBe('object')
        ->and($schema['properties'])->toBe([])
        ->and($schema['required'])->toBe([]);
});

test('toolSchemaArray builds an ObjectSchema when no raw schema is provided', function () {
    $tool = new class implements Tool
    {
        public function description(): string
        {
            return '';
        }

        public function handle(Request $request): string
        {
            return '';
        }

        public function schema(JsonSchema $schema): array
        {
            return [
                'name' => $schema->string()->required()->description('first'),
            ];
        }
    };

    $schema = metadataResolver()->toolSchemaArray($tool);

    expect($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKey('name')
        ->and($schema['required'])->toContain('name');
});
