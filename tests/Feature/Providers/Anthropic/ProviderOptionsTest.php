<?php

namespace Tests\Feature\Providers\Anthropic;

use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ProviderOptionsAgent;
use Tests\Feature\Agents\ProviderOptionsWithToolsAgent;

class ProviderOptionsTest extends AnthropicTestCase
{
    public function test_provider_options_are_included_in_anthropic_request_body(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new ProviderOptionsAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['thinking'])
                && $body['thinking']['type'] === 'enabled'
                && $body['thinking']['budget_tokens'] === 10000;
        });
    }

    public function test_request_body_does_not_contain_provider_options_when_agent_does_not_implement_interface(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            return ! isset($request->data()['thinking']);
        });
    }

    public function test_provider_options_are_persisted_in_tool_call_follow_up_requests(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ProviderOptionsWithToolsAgent)->prompt(
            'Generate a random number',
            provider: 'anthropic',
        );

        $this->assertSame('The number is 72019', $response->text);

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $firstBody = $recorded[0][0]->data();
        $this->assertSame('enabled', $firstBody['thinking']['type']);
        $this->assertSame(10000, $firstBody['thinking']['budget_tokens']);

        $secondBody = $recorded[1][0]->data();
        $this->assertArrayHasKey('thinking', $secondBody);
        $this->assertSame('enabled', $secondBody['thinking']['type']);
        $this->assertSame(10000, $secondBody['thinking']['budget_tokens']);
    }
}
