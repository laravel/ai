<?php

namespace Tests\Feature\Providers\OpenRouter;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

use function Laravel\Ai\agent;

class BaseUrlTest extends TestCase
{
    protected string $customUrl = 'http://localhost:1234/v1';

    public function test_openrouter_text_requests_use_the_configured_base_url(): void
    {
        $this->configureOpenRouterProvider($this->customUrl);

        Http::fake([
            '*' => Http::response([
                'id' => 'chatcmpl-123',
                'object' => 'chat.completion',
                'model' => 'anthropic/claude-sonnet-4.6',
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

        $response = agent()->prompt('Hello', provider: 'openrouter');

        $this->assertSame('Hello from local model', $response->text);

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', "{$this->customUrl}/chat/completions");
    }

    public function test_openrouter_requests_fall_back_to_the_default_base_url(): void
    {
        $this->configureOpenRouterProvider();

        Http::fake([
            '*' => Http::response([
                'id' => 'chatcmpl-456',
                'object' => 'chat.completion',
                'model' => 'anthropic/claude-sonnet-4.6',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello from OpenRouter',
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 1,
                    'completion_tokens' => 1,
                ],
            ]),
        ]);

        $response = agent()->prompt('Hello', provider: 'openrouter');

        $this->assertSame('Hello from OpenRouter', $response->text);

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', 'https://openrouter.ai/api/v1/chat/completions');
    }

    protected function configureOpenRouterProvider(?string $url = null): void
    {
        config(['ai.providers.openrouter' => array_filter([
            ...config('ai.providers.openrouter'),
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
