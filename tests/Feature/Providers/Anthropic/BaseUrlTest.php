<?php

namespace Tests\Feature\Providers\Anthropic;

use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\AssistantAgent;

class BaseUrlTest extends AnthropicTestCase
{
    public function test_anthropic_requests_use_the_configured_base_url(): void
    {
        config(['ai.providers.anthropic.url' => 'https://custom-proxy.example.com/v1']);

        Http::fake([
            'custom-proxy.example.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'https://custom-proxy.example.com/v1/messages';
        });
    }

    public function test_anthropic_requests_fall_back_to_the_default_base_url(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages';
        });
    }
}
