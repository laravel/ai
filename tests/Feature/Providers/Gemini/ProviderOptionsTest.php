<?php

namespace Tests\Feature\Providers\Gemini;

use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ProviderOptionsAgent;
use Tests\Feature\Agents\ProviderOptionsWithToolsAgent;

class ProviderOptionsTest extends GeminiTestCase
{
    public function test_provider_options_are_included_in_generation_config(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new ProviderOptionsAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();
            $config = $body['generationConfig'] ?? [];

            return isset($config['thinkingConfig'])
                && $config['thinkingConfig']['thinkingBudget'] === 10000;
        });
    }

    public function test_request_body_does_not_contain_provider_options_when_agent_does_not_implement_interface(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            $config = $request->data()['generationConfig'] ?? [];

            return ! isset($config['thinkingConfig']);
        });
    }

    public function test_provider_options_are_persisted_in_tool_call_follow_up_requests(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ProviderOptionsWithToolsAgent)->prompt(
            'Generate a random number',
            provider: 'gemini',
        );

        $this->assertSame('The number is 72019', $response->text);

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $firstConfig = $recorded[0][0]->data()['generationConfig'] ?? [];
        $this->assertSame(10000, $firstConfig['thinkingConfig']['thinkingBudget']);

        $secondConfig = $recorded[1][0]->data()['generationConfig'] ?? [];
        $this->assertArrayHasKey('thinkingConfig', $secondConfig);
        $this->assertSame(10000, $secondConfig['thinkingConfig']['thinkingBudget']);
    }
}
