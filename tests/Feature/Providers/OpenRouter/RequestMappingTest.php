<?php

namespace Tests\Feature\Providers\OpenRouter;

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

        config(['ai.providers.openrouter' => [
            ...config('ai.providers.openrouter'),
            'key' => 'test-key',
        ]]);
    }

    public function test_request_includes_model_and_messages(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterResponse('Hello')]);

        agent()->prompt('Hi there', provider: 'openrouter', model: 'anthropic/claude-sonnet-4.6');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'anthropic/claude-sonnet-4.6'
                && count($body['messages']) >= 1
                && collect($body['messages'])->contains(fn ($m) => $m['role'] === 'user' && $m['content'] === 'Hi there');
        });
    }

    public function test_system_instructions_are_sent_as_system_message(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterResponse('Hello')]);

        (new AssistantAgent)->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains($systemMsg['content'], 'helpful assistant');
        });
    }

    public function test_temperature_and_max_tokens_are_included_when_set_via_attributes(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterResponse('Hello')]);

        (new AttributeAgent)->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return data_get($body, 'temperature') === 0.7
                && data_get($body, 'max_completion_tokens') === 4096;
        });
    }

    public function test_temperature_and_max_tokens_are_excluded_when_not_set(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('max_completion_tokens', $body);
        });
    }

    public function test_tools_include_tool_choice_auto(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterResponse('42')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'openrouter');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $body['tool_choice'] === 'auto'
                && is_array($body['tools'])
                && count($body['tools']) > 0;
        });
    }

    public function test_request_without_tools_excludes_tool_fields(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('tools', $body)
                && ! array_key_exists('tool_choice', $body);
        });
    }

    public function test_structured_output_includes_json_schema_response_format(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterResponse('{"symbol": "Au"}')]);

        (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openrouter');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $format = data_get($body, 'response_format');

            return $format['type'] === 'json_schema'
                && isset($format['json_schema']['name'])
                && isset($format['json_schema']['schema'])
                && $format['json_schema']['strict'] === true;
        });
    }

    public function test_request_without_schema_excludes_response_format(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('response_format', $body);
        });
    }

    public function test_streaming_request_includes_stream_options(): void
    {
        Http::fake(['*' => Http::response("data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"Hi\"},\"finish_reason\":null}]}\n\ndata: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":1,\"completion_tokens\":1}}\n\ndata: [DONE]\n\n")]);

        $stream = agent()->stream('Hello', provider: 'openrouter');

        // Consume the stream
        foreach ($stream as $event) {
            //
        }

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $body['stream'] === true
                && data_get($body, 'stream_options.include_usage') === true;
        });
    }

    public function test_request_sends_bearer_token_authorization(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer test-key');
        });
    }

    public function test_request_sends_http_referer_and_x_title_headers_when_configured(): void
    {
        config(['ai.providers.openrouter' => [
            ...config('ai.providers.openrouter'),
            'key' => 'test-key',
            'http_referer' => 'https://example.com',
            'x_title' => 'My App',
        ]]);

        Http::fake(['*' => $this->fakeOpenRouterResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('HTTP-Referer', 'https://example.com')
                && $request->hasHeader('X-Title', 'My App');
        });
    }

    public function test_response_text_is_correctly_parsed(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterResponse('Laravel is great')]);

        $response = agent()->prompt('Tell me about Laravel', provider: 'openrouter');

        $this->assertSame('Laravel is great', $response->text);
        $this->assertSame('openrouter', $response->meta->provider);
    }

    public function test_response_usage_is_correctly_parsed(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'anthropic/claude-sonnet-4.6',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ])]);

        $response = agent()->prompt('Hello', provider: 'openrouter');

        $this->assertSame(10, $response->usage->promptTokens);
        $this->assertSame(5, $response->usage->completionTokens);
    }

    public function test_response_usage_includes_cache_and_reasoning_tokens(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'anthropic/claude-sonnet-4.6',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'prompt_tokens_details' => [
                    'cached_tokens' => 20,
                    'cache_write_tokens' => 80,
                ],
                'completion_tokens_details' => [
                    'reasoning_tokens' => 10,
                ],
            ],
        ])]);

        $response = agent()->prompt('Hello', provider: 'openrouter');

        $this->assertSame(100, $response->usage->promptTokens);
        $this->assertSame(50, $response->usage->completionTokens);
        $this->assertSame(20, $response->usage->cacheReadInputTokens);
        $this->assertSame(80, $response->usage->cacheWriteInputTokens);
        $this->assertSame(10, $response->usage->reasoningTokens);
    }

    public function test_structured_response_is_correctly_parsed(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterResponse('{"symbol": "Au"}')]);

        $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openrouter');

        $this->assertSame('Au', $response->structured['symbol']);
    }

    protected function fakeOpenRouterResponse(string $text): PromiseInterface
    {
        return Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'anthropic/claude-sonnet-4.6',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $text,
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 1,
                'completion_tokens' => 1,
            ],
        ]);
    }
}
