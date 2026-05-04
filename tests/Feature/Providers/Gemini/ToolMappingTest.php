<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\NamedToolAgent;
use Tests\Fixtures\Agents\NullableToolAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

test('empty schema omits parameters key', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'gemini',
    );

    Http::assertSent(function ($request) {
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

test('tool parameters exclude additional properties', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'gemini',
    );

    Http::assertSent(function ($request) {
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

test('nullable tool parameters use OpenAPI-style nullable format', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    (new NullableToolAgent)->prompt('Test nullable params', provider: 'gemini');

    Http::assertSent(function ($request) {
        $props = $request->data()['tools'][0]['function_declarations'][0]['parameters']['properties'];

        return $props['name'] === ['type' => 'string']
            && $props['email'] === ['type' => 'string', 'nullable' => true]
            && $props['age'] === ['type' => 'integer', 'nullable' => true];
    });
});

test('tool with a name() method emits the declared name', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    (new NamedToolAgent('aliased_tool'))->prompt('Search', provider: 'gemini');

    Http::assertSent(function ($request) {
        $names = [];

        foreach ($request->data()['tools'] ?? [] as $toolGroup) {
            foreach ($toolGroup['function_declarations'] ?? [] as $decl) {
                $names[] = $decl['name'];
            }
        }

        return in_array('aliased_tool', $names, true);
    });
});

test('tool without a name() method falls back to class basename', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

    Http::assertSent(function ($request) {
        $names = [];

        foreach ($request->data()['tools'] ?? [] as $toolGroup) {
            foreach ($toolGroup['function_declarations'] ?? [] as $decl) {
                $names[] = $decl['name'];
            }
        }

        return in_array('FixedNumberGenerator', $names, true);
    });
});

test('tools are wrapped in function declarations', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'gemini',
    );

    Http::assertSent(function ($request) {
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
