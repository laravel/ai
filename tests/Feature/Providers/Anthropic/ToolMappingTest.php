<?php

namespace Tests\Feature\Providers\Anthropic;

use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ToolUsingAgent;

class ToolMappingTest extends AnthropicTestCase
{
    public function test_tool_parameters_are_not_wrapped_in_schema_definition(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $tools = $request->data()['tools'] ?? [];

            foreach ($tools as $tool) {
                if ($tool['name'] === 'FixedNumberGenerator') {
                    $properties = (array) ($tool['input_schema']['properties'] ?? []);

                    return $tool['input_schema']['type'] === 'object'
                        && ! isset($properties['schema_definition']);
                }
            }

            return false;
        });
    }

    public function test_empty_schema_still_includes_input_schema_with_type_object(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $tools = $request->data()['tools'] ?? [];

            foreach ($tools as $tool) {
                if ($tool['name'] === 'FixedNumberGenerator') {
                    return isset($tool['input_schema'])
                        && $tool['input_schema']['type'] === 'object'
                        && isset($tool['input_schema']['properties']);
                }
            }

            return false;
        });
    }
}
