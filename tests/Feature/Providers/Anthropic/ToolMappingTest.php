<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Tools\FileSearch;
use Tests\Feature\Agents\ToolUsingAgent;
use Tests\Feature\Providers\Anthropic\AnthropicHelpers;

use function Laravel\Ai\agent;

uses(AnthropicHelpers::class);

test('tool parameters are not wrapped in schema definition', function () {
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
});

test('unsupported provider tool throws logic exception', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    agent(
        'Test unsupported tool',
        tools: [new FileSearch(['store_1'])],
    )->prompt(
        'Search for something',
        provider: 'anthropic',
    );
})->throws(LogicException::class, 'is not supported by Anthropic');

test('empty schema still includes input schema with type object', function () {
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
});
