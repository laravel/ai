<?php

namespace Tests\Feature\Providers\Gemini;

use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ToolUsingAgent;

class ToolMappingTest extends GeminiTestCase
{
    public function test_tool_parameters_use_object_type(): void
    {
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
    }

    public function test_empty_schema_omits_parameters_key(): void
    {
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
    }

    public function test_tool_parameters_exclude_additional_properties(): void
    {
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
    }

    public function test_tools_are_wrapped_in_function_declarations(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            $tools = $request->data()['tools'] ?? [];

            return isset($tools[0]['function_declarations'])
                && count($tools[0]['function_declarations']) > 0;
        });
    }
}
