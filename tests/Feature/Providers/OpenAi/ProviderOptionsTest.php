<?php

namespace Tests\Feature\Providers\OpenAi;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ProviderOptionsAgent;
use Tests\TestCase;

use function Laravel\Ai\agent;

class ProviderOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.openai' => [
            ...config('ai.providers.openai'),
            'key' => 'test-key',
        ]]);
    }

    public function test_provider_options_are_included_in_openai_request_body(): void
    {
        Http::fake([
            '*' => $this->fakeOpenAiResponse('Hello'),
        ]);

        (new ProviderOptionsAgent)->prompt('Hello', provider: 'openai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return data_get($body, 'reasoning.effort') === 'high'
                && data_get($body, 'frequency_penalty') === 0.5
                && data_get($body, 'presence_penalty') === 0.3;
        });
    }

    public function test_request_body_does_not_contain_provider_options_when_agent_does_not_implement_interface(): void
    {
        Http::fake([
            '*' => $this->fakeOpenAiResponse('Hello'),
        ]);

        agent()->prompt('Hello', provider: 'openai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('reasoning', $body)
                && ! array_key_exists('frequency_penalty', $body)
                && ! array_key_exists('presence_penalty', $body);
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
