<?php

namespace Tests\Feature\Providers\Mistral;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\AttributeAgent;
use Tests\Feature\Agents\StructuredAgent;
use Tests\Feature\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

class RequestMappingTest extends MistralTestCase
{
    public function test_request_includes_model_and_messages(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hi there', provider: 'mistral', model: 'mistral-large-latest');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'mistral-large-latest'
                && count($body['messages']) >= 1
                && collect($body['messages'])->contains(fn ($m) => $m['role'] === 'user' && $m['content'] === 'Hi there');
        });
    }

    public function test_system_instructions_are_sent_as_system_message(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        (new AssistantAgent)->prompt('Hello', provider: 'mistral');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains($systemMsg['content'], 'helpful assistant');
        });
    }

    public function test_temperature_and_max_tokens_are_included_when_set_via_attributes(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        (new AttributeAgent)->prompt('Hello', provider: 'mistral');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return data_get($body, 'temperature') === 0.7
                && data_get($body, 'max_tokens') === 4096;
        });
    }

    public function test_temperature_and_max_tokens_are_excluded_when_not_set(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'mistral');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('max_tokens', $body);
        });
    }

    public function test_tools_include_tool_choice_auto(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('42')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'mistral');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $body['tool_choice'] === 'auto'
                && is_array($body['tools'])
                && count($body['tools']) > 0;
        });
    }

    public function test_request_without_tools_excludes_tool_fields(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'mistral');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('tools', $body)
                && ! array_key_exists('tool_choice', $body);
        });
    }

    public function test_structured_output_includes_json_schema_response_format(): void
    {
        Http::fake(['*' => $this->fakeStructuredResponse('{"symbol": "Au"}')]);

        (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'mistral');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $format = data_get($body, 'response_format');

            return $format['type'] === 'json_schema'
                && isset($format['json_schema']['name'])
                && isset($format['json_schema']['schema'])
                && $format['json_schema']['strict'] === true;
        });
    }

    public function test_streaming_request_includes_stream_options(): void
    {
        Http::fake(['*' => Http::response("data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"Hi\"},\"finish_reason\":null}]}\n\ndata: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":1,\"completion_tokens\":1}}\n\ndata: [DONE]\n\n")]);

        $stream = agent()->stream('Hello', provider: 'mistral');

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
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'mistral');

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer test-key');
        });
    }

    public function test_response_text_is_correctly_parsed(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Laravel is great')]);

        $response = agent()->prompt('Tell me about Laravel', provider: 'mistral');

        $this->assertSame('Laravel is great', $response->text);
        $this->assertSame('mistral', $response->meta->provider);
    }

    public function test_response_usage_is_correctly_parsed(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'mistral-medium-latest',
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

        $response = agent()->prompt('Hello', provider: 'mistral');

        $this->assertSame(10, $response->usage->promptTokens);
        $this->assertSame(5, $response->usage->completionTokens);
    }

    public function test_structured_response_is_correctly_parsed(): void
    {
        Http::fake(['*' => $this->fakeStructuredResponse('{"symbol": "Au"}')]);

        $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'mistral');

        $this->assertSame('Au', $response->structured['symbol']);
    }
}
