<?php

namespace Tests\Feature\Providers\Gemini;

use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\AssistantAgent;

class BaseUrlTest extends GeminiTestCase
{
    public function test_gemini_requests_use_the_configured_base_url(): void
    {
        config(['ai.providers.gemini.url' => 'https://custom-proxy.example.com/v1']);

        Http::fake([
            'custom-proxy.example.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'https://custom-proxy.example.com/v1');
        });
    }

    public function test_gemini_requests_fall_back_to_the_default_base_url(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'generativelanguage.googleapis.com');
        });
    }
}
