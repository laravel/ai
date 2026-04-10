<?php

namespace Tests\Feature\Providers\OpenAi;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\AttributeAgent;
use Tests\Feature\Agents\StructuredAgent;
use Tests\Feature\Tools\RandomNumberGenerator;
use Tests\TestCase;

use function Laravel\Ai\agent;

class RequestMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.openai' => [
            ...config('ai.providers.openai'),
            'key' => 'test-key',
        ]]);
    }

    public function test_request_includes_model_and_input(): void
    {
        Http::fake(['*' => $this->fakeOpenAiResponse('Hello')]);

        agent()->prompt('Hi there', provider: 'openai', model: 'gpt-5.4');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'gpt-5.4'
                && is_array($body['input'])
                && collect($body['input'])->contains(fn ($m) => $m['role'] === 'user'
                    && collect($m['content'])->contains(fn ($c) => ($c['text'] ?? '') === 'Hi there'));
        });
    }

    public function test_system_instructions_are_sent_as_system_message_in_input(): void
    {
        Http::fake(['*' => $this->fakeOpenAiResponse('Hello')]);

        (new AssistantAgent)->prompt('Hello', provider: 'openai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $systemMsg = collect($body['input'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains($systemMsg['content'], 'helpful assistant');
        });
    }

    public function test_temperature_and_max_tokens_are_included_when_set_via_attributes(): void
    {
        Http::fake(['*' => $this->fakeOpenAiResponse('Hello')]);

        (new AttributeAgent)->prompt('Hello', provider: 'openai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return data_get($body, 'temperature') === 0.7
                && data_get($body, 'max_output_tokens') === 4096;
        });
    }

    public function test_temperature_and_max_tokens_are_excluded_when_not_set(): void
    {
        Http::fake(['*' => $this->fakeOpenAiResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('max_output_tokens', $body);
        });
    }

    public function test_tools_include_tool_choice_auto(): void
    {
        Http::fake(['*' => $this->fakeOpenAiResponse('42')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'openai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $body['tool_choice'] === 'auto'
                && is_array($body['tools'])
                && count($body['tools']) > 0;
        });
    }

    public function test_request_without_tools_excludes_tool_fields(): void
    {
        Http::fake(['*' => $this->fakeOpenAiResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('tools', $body)
                && ! array_key_exists('tool_choice', $body);
        });
    }

    public function test_structured_output_includes_json_schema_text_format(): void
    {
        Http::fake(['*' => $this->fakeOpenAiResponse('{"symbol": "Au"}')]);

        (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $format = data_get($body, 'text.format');

            return $format['type'] === 'json_schema'
                && isset($format['name'])
                && isset($format['schema'])
                && $format['strict'] === true;
        });
    }

    public function test_request_without_schema_excludes_text_format(): void
    {
        Http::fake(['*' => $this->fakeOpenAiResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('text', $body);
        });
    }

    public function test_request_sends_bearer_token_authorization(): void
    {
        Http::fake(['*' => $this->fakeOpenAiResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openai');

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer test-key');
        });
    }

    public function test_response_text_is_correctly_parsed(): void
    {
        Http::fake(['*' => $this->fakeOpenAiResponse('Laravel is great')]);

        $response = agent()->prompt('Tell me about Laravel', provider: 'openai');

        $this->assertSame('Laravel is great', $response->text);
        $this->assertSame('openai', $response->meta->provider);
    }

    public function test_response_usage_is_correctly_parsed(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'resp_123',
            'status' => 'completed',
            'model' => 'gpt-5.4',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'Hello',
                ]],
            ]],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
            ],
        ])]);

        $response = agent()->prompt('Hello', provider: 'openai');

        $this->assertSame(10, $response->usage->promptTokens);
        $this->assertSame(5, $response->usage->completionTokens);
    }

    public function test_structured_response_is_correctly_parsed(): void
    {
        Http::fake(['*' => $this->fakeOpenAiResponse('{"symbol": "Au"}')]);

        $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openai');

        $this->assertSame('Au', $response->structured['symbol']);
    }

    protected function fakeOpenAiResponse(string $text): PromiseInterface
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
