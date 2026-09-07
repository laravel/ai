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

test('empty schema omits parameters key', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'gemini',
    );

    Http::assertSent(function ($request): bool {
        $tools = $request->data()['tools'] ?? [];

        foreach ($tools as $toolGroup) {
            foreach ($toolGroup['function_declarations'] ?? [] as $decl) {
                if ($decl['name'] === 'FixedNumberGenerator') {
                    return ! isset($decl['parameters']);
                }
            }
        }

        return false;
    });
});

test('tool parameters exclude additional properties', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'gemini',
    );

    Http::assertSent(function ($request): bool {
        $tools = $request->data()['tools'] ?? [];

        foreach ($tools as $toolGroup) {
            foreach ($toolGroup['function_declarations'] ?? [] as $decl) {
                if ($decl['name'] === 'FixedNumberGenerator') {
                    return ! isset($decl['parameters']['additionalProperties'])
                        || $decl['parameters']['additionalProperties'] === false;
                }
            }
        }

        return false;
    });
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

    Http::assertSent(function ($request) use ($hasAdditionalProperties): bool {
        $params = $request->data()['tools'][0]['function_declarations'][0]['parameters'];

        // The nested object lives under the array's items and must survive the strip.
        expect($params['properties']['items']['items']['properties'])
            ->toHaveKeys(['name', 'description']);

        return $hasAdditionalProperties($params) === false;
    });
});

test('nullable tool parameters use OpenAPI-style nullable format', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    (new NullableToolAgent)->prompt('Test nullable params', provider: 'gemini');

    Http::assertSent(function ($request): bool {
        $props = $request->data()['tools'][0]['function_declarations'][0]['parameters']['properties'];

        return $props['name'] === ['type' => 'string']
            && $props['email'] === ['type' => 'string', 'nullable' => true]
            && $props['age'] === ['type' => 'integer', 'nullable' => true];
    });
});

test('tool with a name() method emits the declared name', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    (new NamedToolAgent('aliased_tool'))->prompt('Search', provider: 'gemini');

    Http::assertSent(function ($request): bool {
        $names = [];

        foreach ($request->data()['tools'] ?? [] as $toolGroup) {
            foreach ($toolGroup['function_declarations'] ?? [] as $decl) {
                $names[] = $decl['name'];
            }
        }

        return in_array('aliased_tool', $names, true);
    });
});

test('tool without a name() method falls back to class basename', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

    Http::assertSent(function ($request): bool {
        $names = [];

        foreach ($request->data()['tools'] ?? [] as $toolGroup) {
            foreach ($toolGroup['function_declarations'] ?? [] as $decl) {
                $names[] = $decl['name'];
            }
        }

        return in_array('FixedNumberGenerator', $names, true);
    });
});

test('provider tools are sent without function_calling_config', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(
        'Answer using the uploaded knowledge base.',
        tools: [new FileSearch(['fileSearchStores/store123'])],
    )->prompt('Question?', provider: 'gemini');

    Http::assertSent(function ($request): bool {
        $body = $request->data();
        $tools = $body['tools'] ?? [];

        return isset($tools[0]['fileSearch']['fileSearchStoreNames'])
            && $tools[0]['fileSearch']['fileSearchStoreNames'] === ['fileSearchStores/store123']
            && ! isset($body['tool_config']);
    });
});

test('mixed function and provider tools are sent without tool_config', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(
        'Generate a number, optionally searching the web.',
        tools: [new FixedNumberGenerator, new WebSearch],
    )->prompt('Generate', provider: 'gemini');

    Http::assertSent(function ($request): bool {
        $body = $request->data();
        $tools = $body['tools'] ?? [];

        $hasFunctionDeclarations = collect($tools)->contains(fn ($tool): bool => isset($tool['function_declarations']));
        $hasGoogleSearch = collect($tools)->contains(fn ($tool): bool => isset($tool['google_search']));

        return $hasFunctionDeclarations
            && $hasGoogleSearch
            && ! isset($body['tool_config']);
    });
});

test('tools are wrapped in function declarations', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'gemini',
    );

    Http::assertSent(function ($request): bool {
        $tools = $request->data()['tools'] ?? [];

        if (! isset($tools[0]['function_declarations']) || count($tools[0]['function_declarations']) === 0) {
            return false;
        }

        $decl = $tools[0]['function_declarations'][0];

        return isset($decl['name'])
            && isset($decl['description'])
            && is_string($decl['description']);
    });
});

test('code execution tool sends code_execution definition', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(tools: [new CodeExecution])->prompt('Run some code', provider: 'gemini');

    Http::assertSent(fn ($request): bool => str_contains($request->body(), '"code_execution":{}'));
});
