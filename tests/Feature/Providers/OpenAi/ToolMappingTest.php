<?php

namespace Tests\Feature\Providers\OpenAi;

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

        config(['ai.providers.openai' => [
            ...config('ai.providers.openai'),
            'key' => 'test-key',
        ]]);
    }

    public function test_tool_with_parameters_includes_strict_compliant_schema(): void
    {
        Http::fake([
            '*' => $this->fakeOpenAiResponse('42'),
        ]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'openai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

            return $tool['strict'] === true
                && $tool['parameters']['type'] === 'object'
                && array_key_exists('min', $tool['parameters']['properties'])
                && array_key_exists('max', $tool['parameters']['properties'])
                && in_array('min', $tool['parameters']['required'])
                && in_array('max', $tool['parameters']['required'])
                && $tool['parameters']['additionalProperties'] === false;
        });
    }

    public function test_tool_with_empty_schema_includes_strict_compliant_parameters(): void
    {
        Http::fake([
            '*' => $this->fakeOpenAiResponse('72019'),
        ]);

        agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'openai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

            return $tool['strict'] === true
                && array_key_exists('parameters', $tool)
                && $tool['parameters']['type'] === 'object'
                && $tool['parameters']['properties'] === []
                && $tool['parameters']['required'] === []
                && $tool['parameters']['additionalProperties'] === false;
        });
    }

    protected function fakeOpenAiResponse(string $text): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'id' => 'resp_123',
            'status' => 'completed',
            'model' => 'gpt-5.4',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'content' => [[
                    'type' => 'output_text',
                    'text' => $text,
                ]],
            ]],
            'usage' => [
                'input_tokens' => 1,
                'output_tokens' => 1,
            ],
        ]);
    }
}
