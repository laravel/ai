<?php

namespace Tests\Feature\Providers\Mistral;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Laravel\Ai\agent;

class BaseUrlTest extends MistralTestCase
{
    protected string $customUrl = 'http://localhost:1234/v1';

    public function test_mistral_requests_use_the_configured_base_url(): void
    {
        $this->configureMistralProvider($this->customUrl);

        Http::fake([
            '*' => $this->fakeTextResponse('Hello from custom'),
        ]);

        $response = agent()->prompt('Hello', provider: 'mistral');

        $this->assertSame('Hello from custom', $response->text);

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', "{$this->customUrl}/chat/completions");
    }

    public function test_mistral_requests_fall_back_to_the_default_base_url(): void
    {
        Http::fake([
            '*' => $this->fakeTextResponse('Hello from Mistral'),
        ]);

        $response = agent()->prompt('Hello', provider: 'mistral');

        $this->assertSame('Hello from Mistral', $response->text);

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', 'https://api.mistral.ai/v1/chat/completions');
    }

    protected function configureMistralProvider(?string $url = null): void
    {
        config(['ai.providers.mistral' => array_filter([
            ...config('ai.providers.mistral'),
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
