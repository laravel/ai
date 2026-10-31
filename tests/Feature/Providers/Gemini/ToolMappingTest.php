<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Tools\CodeExecution;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\FileSearchQuery;
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

test('file search metadata filter escapes quotes and trailing backslashes', function (string $method, string $expected): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(tools: [new FileSearch(['fileSearchStores/store123'], where: function (FileSearchQuery $query) use ($method): void {
        if (in_array($method, ['whereIn', 'whereNotIn'])) {
            $query->{$method}('category', ['x" OR tenant="globex', 'x\\']);
        } else {
            $query->{$method}('category', 'x" OR tenant="globex')->{$method}('path', 'x\\');
        }
    })])->prompt('Question?', provider: 'gemini');

    expect(sentRequest()->data()['tools'][0]['metadata_filter'])->toBe($expected);
})->with([
    'where' => ['where', '(category="x\\" OR tenant=\\"globex") AND (path="x\\\\")'],
    'whereNot' => ['whereNot', '(category!="x\\" OR tenant=\\"globex") AND (path!="x\\\\")'],
    'whereIn' => ['whereIn', '(category="x\\" OR tenant=\\"globex" OR category="x\\\\")'],
    'whereNotIn' => ['whereNotIn', '(category!="x\\" OR tenant=\\"globex" AND category!="x\\\\")'],
]);

test('file search metadata filter rejects invalid keys before sending a request', function (string $key): void {
    Http::fake();

    expect(fn () => agent(tools: [new FileSearch(['fileSearchStores/store123'], where: [$key => 'value'])])
        ->prompt('Question?', provider: 'gemini'))
        ->toThrow(InvalidArgumentException::class, "Invalid metadata filter key [{$key}].");

    Http::assertNothingSent();
})->with(['tenant="acme" OR category', 'tenant name', 'tenant-name', '1tenant', '', "tenant\n"]);

test('file search metadata filter groups ordinary conditions and preserves numeric values', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(tools: [new FileSearch(['fileSearchStores/store123'], where: fn (FileSearchQuery $query) => $query
        ->where('tenant', 'acme')
        ->whereIn('category', ['guide', 'reference'])
        ->whereNot('_status', 'draft')
        ->where('version.number', 2.5)
        ->whereIn('year', [2024, '2025'])
        ->whereNot('revision', 0)
    )])->prompt('Question?', provider: 'gemini');

    expect(sentRequest()->data()['tools'][0]['metadata_filter'])->toBe(
        '(tenant="acme") AND (category="guide" OR category="reference") AND (_status!="draft") AND (version.number=2.5) AND (year=2024 OR year=2025) AND (revision!=0)'
    );
});

test('file search metadata filter supports whereNotIn', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(tools: [new FileSearch(['fileSearchStores/store123'], where: fn (FileSearchQuery $query) => $query
        ->where('tenant', 'acme')
        ->whereNotIn('category', ['draft', 'internal'])
        ->whereNotIn('year', [2023, '2024'])
    )])->prompt('Question?', provider: 'gemini');

    expect(sentRequest()->data()['tools'][0]['metadata_filter'])->toBe(
        '(tenant="acme") AND (category!="draft" AND category!="internal") AND (year!=2023 AND year!=2024)'
    );
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
