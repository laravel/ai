<?php

namespace Tests\Feature\Providers\Xai;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

use function Laravel\Ai\agent;

class BaseUrlTest extends TestCase
{
    protected string $customUrl = 'http://localhost:1234/v1';

    public function test_xai_text_requests_use_the_configured_base_url(): void
    {
        $this->configureXaiProvider($this->customUrl);

        Http::fake([
            '*' => Http::response($this->fakeXaiResponseData('Hello from local model')),
        ]);

        $response = agent()->prompt('Hello', provider: 'xai');

        $this->assertSame('Hello from local model', $response->text);

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', "{$this->customUrl}/responses");
    }

    public function test_xai_requests_fall_back_to_the_default_base_url(): void
    {
        $this->configureXaiProvider();

        Http::fake([
            '*' => Http::response($this->fakeXaiResponseData('Hello from xAI')),
        ]);

        $response = agent()->prompt('Hello', provider: 'xai');

        $this->assertSame('Hello from xAI', $response->text);

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', 'https://api.x.ai/v1/responses');
    }

    protected function configureXaiProvider(?string $url = null): void
    {
        config(['ai.providers.xai' => array_filter([
            ...config('ai.providers.xai'),
            'key' => 'test-key',
            'url' => $url,
        ])]);
    }

    protected function assertRequestSent(string $method, string $url): void
    {
        Http::assertSent(fn (Request $request) => $request->method() === $method
            && $request->url() === $url);
    }

    protected function fakeXaiResponseData(string $text): array
    {
        return [
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
        ];
    }
}
