<?php

namespace Tests\Feature\Providers\Xai;

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

        config(['ai.providers.xai' => [
            ...config('ai.providers.xai'),
            'key' => 'test-key',
        ]]);
    }

    public function test_request_includes_model_and_input(): void
    {
        Http::fake(['*' => $this->fakeXaiResponse('Hello')]);

        agent()->prompt('Hi there', provider: 'xai', model: 'grok-4-1-fast-reasoning');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'grok-4-1-fast-reasoning'
                && is_array($body['input'])
                && collect($body['input'])->contains(fn ($m) => $m['role'] === 'user'
                    && collect($m['content'])->contains(fn ($c) => ($c['type'] ?? '') === 'input_text' && $c['text'] === 'Hi there'));
        });
    }

    public function test_system_instructions_are_sent_as_system_message(): void
    {
        Http::fake(['*' => $this->fakeXaiResponse('Hello')]);

        (new AssistantAgent)->prompt('Hello', provider: 'xai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $systemMsg = collect($body['input'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains($systemMsg['content'], 'helpful assistant');
        });
    }

    public function test_temperature_and_max_tokens_are_included_when_set_via_attributes(): void
    {
        Http::fake(['*' => $this->fakeXaiResponse('Hello')]);

        (new AttributeAgent)->prompt('Hello', provider: 'xai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return data_get($body, 'temperature') === 0.7
                && data_get($body, 'max_output_tokens') === 4096;
        });
    }

    public function test_temperature_and_max_tokens_are_excluded_when_not_set(): void
    {
        Http::fake(['*' => $this->fakeXaiResponse('Hello')]);

        agent()->prompt('Hello', provider: 'xai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('max_output_tokens', $body);
        });
    }

    public function test_tools_include_tool_choice_auto(): void
    {
        Http::fake(['*' => $this->fakeXaiResponse('42')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'xai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $body['tool_choice'] === 'auto'
                && is_array($body['tools'])
                && count($body['tools']) > 0;
        });
    }

    public function test_request_without_tools_excludes_tool_fields(): void
    {
        Http::fake(['*' => $this->fakeXaiResponse('Hello')]);

        agent()->prompt('Hello', provider: 'xai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('tools', $body)
                && ! array_key_exists('tool_choice', $body);
        });
    }

    public function test_structured_output_includes_json_schema_text_format(): void
    {
        Http::fake(['*' => $this->fakeXaiResponse('{"symbol": "Au"}')]);

        (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'xai');

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
        Http::fake(['*' => $this->fakeXaiResponse('Hello')]);

        agent()->prompt('Hello', provider: 'xai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('text', $body);
        });
    }

    public function test_streaming_request_includes_stream_flag(): void
    {
        $sseData = "data: {\"type\":\"response.created\",\"response\":{\"id\":\"resp_123\",\"model\":\"grok-4-1-fast-reasoning\"}}\n\n"
            ."data: {\"type\":\"response.output_text.delta\",\"delta\":\"Hi\"}\n\n"
            ."data: {\"type\":\"response.output_text.done\"}\n\n"
            ."data: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_123\",\"usage\":{\"input_tokens\":1,\"output_tokens\":1}}}\n\n";

        Http::fake(['*' => Http::response($sseData)]);

        $stream = agent()->stream('Hello', provider: 'xai');

        foreach ($stream as $event) {
            //
        }

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $body['stream'] === true;
        });
    }

    public function test_request_sends_bearer_token_authorization(): void
    {
        Http::fake(['*' => $this->fakeXaiResponse('Hello')]);

        agent()->prompt('Hello', provider: 'xai');

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer test-key');
        });
    }

    public function test_response_text_is_correctly_parsed(): void
    {
        Http::fake(['*' => $this->fakeXaiResponse('Laravel is great')]);

        $response = agent()->prompt('Tell me about Laravel', provider: 'xai');

        $this->assertSame('Laravel is great', $response->text);
        $this->assertSame('xai', $response->meta->provider);
    }

    public function test_response_usage_is_correctly_parsed(): void
    {
        Http::fake(['*' => Http::response([
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
                        ['type' => 'output_text', 'text' => 'Hello'],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
            ],
        ])]);

        $response = agent()->prompt('Hello', provider: 'xai');

        $this->assertSame(10, $response->usage->promptTokens);
        $this->assertSame(5, $response->usage->completionTokens);
    }

    public function test_structured_response_is_correctly_parsed(): void
    {
        Http::fake(['*' => $this->fakeXaiResponse('{"symbol": "Au"}')]);

        $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'xai');

        $this->assertSame('Au', $response->structured['symbol']);
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
