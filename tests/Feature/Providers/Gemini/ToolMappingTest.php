<?php

use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ToolUsingAgent;
use Tests\Feature\Providers\Gemini\GeminiHelpers;

uses(GeminiHelpers::class);

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
