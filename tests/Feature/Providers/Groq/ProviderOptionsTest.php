<?php

namespace Tests\Feature\Providers\Groq;

use GuzzleHttp\Promise\PromiseInterface;
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

        config(['ai.providers.groq' => [
            ...config('ai.providers.groq'),
            'key' => 'test-key',
        ]]);
    }

    public function test_provider_options_are_included_in_groq_request_body(): void
    {
        Http::fake([
            '*' => $this->fakeGroqResponse('Hello'),
        ]);

        (new ProviderOptionsAgent)->prompt('Hello', provider: 'groq');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return data_get($body, 'frequency_penalty') === 0.5
                && data_get($body, 'presence_penalty') === 0.3;
        });
    }

    public function test_request_body_does_not_contain_provider_options_when_agent_does_not_implement_interface(): void
    {
        Http::fake([
            '*' => $this->fakeGroqResponse('Hello'),
        ]);

        agent()->prompt('Hello', provider: 'groq');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('reasoning', $body)
                && ! array_key_exists('frequency_penalty', $body)
                && ! array_key_exists('presence_penalty', $body);
        });
    }

    protected function fakeGroqResponse(string $text): PromiseInterface
    {
        return Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'openai/gpt-oss-20b',
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
