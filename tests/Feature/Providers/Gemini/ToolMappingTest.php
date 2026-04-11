<?php

use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\NullableToolAgent;
use Tests\Feature\Agents\ToolUsingAgent;

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

    (new NullableToolAgent)->prompt(
        'Test nullable params',
        provider: 'gemini',
    );

    Http::assertSent(function ($request) {
        $tools = $request->data()['tools'] ?? [];

        foreach ($tools as $toolGroup) {
            foreach ($toolGroup['function_declarations'] ?? [] as $decl) {
                if ($decl['name'] !== 'NullableParamTool') {
                    continue;
                }

                $props = $decl['parameters']['properties'] ?? [];

                // Non-nullable param should have string type without nullable flag
                if ($props['name']['type'] !== 'string' || isset($props['name']['nullable'])) {
                    return false;
                }

                // Nullable string param should have single type string with nullable: true
                if ($props['email']['type'] !== 'string' || $props['email']['nullable'] !== true) {
                    return false;
                }

                // Nullable integer param should have single type integer with nullable: true
                if ($props['age']['type'] !== 'integer' || $props['age']['nullable'] !== true) {
                    return false;
                }

                return true;
            }
        }

        return false;
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
