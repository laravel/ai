<?php

namespace Tests\Feature\Providers\Mistral;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Tools\FileSearch;
use RuntimeException;
use Tests\Feature\Tools\FixedNumberGenerator;
use Tests\Feature\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

class ToolMappingTest extends MistralTestCase
{
    public function test_tool_with_parameters_includes_correct_schema(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('42')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'mistral');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
            $function = $tool['function'] ?? [];

            return $function['parameters']['type'] === 'object'
                && array_key_exists('min', $function['parameters']['properties'])
                && array_key_exists('max', $function['parameters']['properties'])
                && in_array('min', $function['parameters']['required'])
                && in_array('max', $function['parameters']['required'])
                && $function['parameters']['additionalProperties'] === false;
        });
    }

    public function test_tool_with_empty_schema_includes_parameters(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('72019')]);

        agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'mistral');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
            $function = $tool['function'] ?? [];

            return array_key_exists('parameters', $function)
                && $function['parameters']['type'] === 'object'
                && $function['parameters']['properties'] === []
                && $function['parameters']['required'] === []
                && $function['parameters']['additionalProperties'] === false;
        });
    }

    public function test_provider_tools_throw_runtime_exception(): void
    {
        Http::fake(['*' => $this->fakeTextResponse()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mistral does not support');

        agent(
            tools: [new FileSearch(['store_1'])],
        )->prompt('Search for something', provider: 'mistral');
    }

    public function test_tool_parameters_are_not_wrapped_in_schema_definition(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('done')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'mistral');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
            $function = $tool['function'] ?? [];

            return ! array_key_exists('schema_definition', $function['parameters']['properties'] ?? [])
                && ! in_array('schema_definition', $function['parameters']['required'] ?? []);
        });
    }
}
