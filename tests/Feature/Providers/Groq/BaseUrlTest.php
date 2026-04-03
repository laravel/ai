<?php

namespace Tests\Feature\Providers\Groq;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

use function Laravel\Ai\agent;

class BaseUrlTest extends TestCase
{
    protected string $customUrl = 'http://localhost:1234/v1';

    public function test_groq_text_requests_use_the_configured_base_url(): void
    {
        $this->configureGroqProvider($this->customUrl);

        Http::fake([
            '*' => Http::response([
                'id' => 'chatcmpl-123',
                'object' => 'chat.completion',
                'model' => 'openai/gpt-oss-20b',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello from local model',
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 1,
                    'completion_tokens' => 1,
                ],
            ]),
        ]);

        $response = agent()->prompt('Hello', provider: 'groq');

        $this->assertSame('Hello from local model', $response->text);

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', "{$this->customUrl}/chat/completions");
    }

    public function test_groq_requests_fall_back_to_the_default_base_url(): void
    {
        $this->configureGroqProvider();

        Http::fake([
            '*' => Http::response([
                'id' => 'chatcmpl-456',
                'object' => 'chat.completion',
                'model' => 'openai/gpt-oss-20b',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello from Groq',
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 1,
                    'completion_tokens' => 1,
                ],
            ]),
        ]);

        $response = agent()->prompt('Hello', provider: 'groq');

        $this->assertSame('Hello from Groq', $response->text);

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', 'https://api.groq.com/openai/v1/chat/completions');
    }

    protected function configureGroqProvider(?string $url = null): void
    {
        config(['ai.providers.groq' => array_filter([
            ...config('ai.providers.groq'),
            'key' => 'test-key',
            'url' => $url,
        ])]);
    }

    protected function assertRequestSent(string $method, string $url): void
    {
        Http::assertSent(fn (Request $request) => $request->method() === $method
            && $request->url() === $url);
    }
}
