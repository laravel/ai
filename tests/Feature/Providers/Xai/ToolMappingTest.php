<?php

namespace Tests\Feature\Providers\Xai;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Tools\FixedNumberGenerator;
use Tests\Feature\Tools\RandomNumberGenerator;
use Tests\TestCase;

use function Laravel\Ai\agent;

class ToolMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.xai' => [
            ...config('ai.providers.xai'),
            'key' => 'test-key',
        ]]);
    }

    public function test_tool_with_parameters_includes_correct_schema(): void
    {
        Http::fake([
            '*' => $this->fakeXaiResponse('42'),
        ]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'xai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

            return $tool['parameters']['type'] === 'object'
                && array_key_exists('min', $tool['parameters']['properties'])
                && array_key_exists('max', $tool['parameters']['properties'])
                && in_array('min', $tool['parameters']['required'])
                && in_array('max', $tool['parameters']['required'])
                && $tool['parameters']['additionalProperties'] === false;
        });
    }

    public function test_tool_with_empty_schema_includes_parameters(): void
    {
        Http::fake([
            '*' => $this->fakeXaiResponse('72019'),
        ]);

        agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'xai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

            return array_key_exists('parameters', $tool)
                && $tool['parameters']['type'] === 'object'
                && $tool['parameters']['properties'] === []
                && $tool['parameters']['required'] === []
                && $tool['parameters']['additionalProperties'] === false;
        });
    }

    public function test_tool_parameters_are_not_wrapped_in_schema_definition(): void
    {
        Http::fake([
            '*' => $this->fakeXaiResponse('done'),
        ]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'xai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

            return ! array_key_exists('schema_definition', $tool['parameters']['properties'] ?? [])
                && ! in_array('schema_definition', $tool['parameters']['required'] ?? []);
        });
    }

    protected function fakeXaiResponse(string $text): PromiseInterface
    {
        return Http::response([
            'id' => 'resp_123',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'grok-4-1-fast-reasoning',
            'output' => [
                [
                    'type' => 'message',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'output_text', 'text' => $text],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 1,
                'output_tokens' => 1,
            ],
        ]);
    }
}
