<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Tools\CodeExecution;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Tests\Fixtures\Agents\NamedToolAgent;
use Tests\Fixtures\Agents\NestedObjectToolAgent;
use Tests\Fixtures\Agents\NullableToolAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

use function Laravel\Ai\agent;

function geminiTool(array $body, string $name): ?array
{
    foreach ($body['tools'] ?? [] as $tool) {
        if (($tool['name'] ?? null) === $name) {
            return $tool;
        }
    }

    return null;
}

test('empty schema omits parameters key', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'gemini',
    );

    expect(geminiTool(sentRequest()->data(), 'FixedNumberGenerator'))
        ->not->toBeNull()
        ->not->toHaveKey('parameters');
});

test('function tools are declared flat with a function type', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'gemini',
    );

    expect(geminiTool(sentRequest()->data(), 'FixedNumberGenerator'))
        ->toMatchArray(['type' => 'function'])
        ->description->toBeString()
        ->and(sentRequest()->data()['tools'][0])->not->toHaveKey('function_declarations');
});

test('nested object parameters recursively exclude additional properties', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    (new NestedObjectToolAgent)->prompt('Test nested params', provider: 'gemini');

    $hasAdditionalProperties = function ($node) use (&$hasAdditionalProperties): bool {
        if (! is_array($node)) {
            return false;
        }

        if (array_key_exists('additionalProperties', $node)) {
            return true;
        }

        foreach ($node as $value) {
            if ($hasAdditionalProperties($value)) {
                return true;
            }
        }

        return false;
    };

    $params = sentRequest()->data()['tools'][0]['parameters'];

    // The nested object lives under the array's items and must survive the strip.
    expect($params['properties']['items']['items']['properties'])->toHaveKeys(['name', 'description'])
        ->and($hasAdditionalProperties($params))->toBeFalse();
});

test('nullable tool parameters use OpenAPI-style nullable format', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    (new NullableToolAgent)->prompt('Test nullable params', provider: 'gemini');

    expect(sentRequest()->data()['tools'][0]['parameters']['properties'])->toMatchArray([
        'name' => ['type' => 'string'],
        'email' => ['type' => 'string', 'nullable' => true],
        'age' => ['type' => 'integer', 'nullable' => true],
    ]);
});

test('tool with a name() method emits the declared name', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    (new NamedToolAgent('aliased_tool'))->prompt('Search', provider: 'gemini');

    expect(geminiTool(sentRequest()->data(), 'aliased_tool'))->not->toBeNull();
});

test('tool without a name() method falls back to class basename', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

    expect(geminiTool(sentRequest()->data(), 'FixedNumberGenerator'))->not->toBeNull();
});

test('provider tools are sent without a tool choice', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(
        'Answer using the uploaded knowledge base.',
        tools: [new FileSearch(['fileSearchStores/store123'])],
    )->prompt('Question?', provider: 'gemini');

    expect(sentRequest()->data()['tools'][0])->toMatchArray([
        'type' => 'file_search',
        'file_search_store_names' => ['fileSearchStores/store123'],
    ])->and(sentRequest()->data()['generation_config'] ?? [])->not->toHaveKey('tool_choice');
});

test('mixed function and provider tools are sent side by side', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(
        'Generate a number, optionally searching the web.',
        tools: [new FixedNumberGenerator, new WebSearch],
    )->prompt('Generate', provider: 'gemini');

    expect(array_column(sentRequest()->data()['tools'], 'type'))->toBe(['function', 'google_search'])
        ->and(sentRequest()->data()['generation_config'] ?? [])->not->toHaveKey('tool_choice');
});

test('code execution tool sends a code_execution definition', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(tools: [new CodeExecution])->prompt('Run some code', provider: 'gemini');

    expect(sentRequest()->data()['tools'])->toBe([['type' => 'code_execution']]);
});
