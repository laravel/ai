<?php

namespace Tests\Feature\Providers\Anthropic;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ProviderOptionsAgent;
use Tests\Feature\Agents\ProviderOptionsWithToolsAgent;
use Tests\Feature\Tools\FixedNumberGenerator;

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

    public function test_cache_control_formats_system_prompt_for_anthropic(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        $this->anthropicCacheControlAgent()->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['system'])
                && is_array($body['system'])
                && $body['system'][0]['type'] === 'text'
                && $body['system'][0]['text'] === 'You are a cacheable assistant.'
                && $body['system'][0]['cache_control']['type'] === 'ephemeral'
                && ! isset($body['cache_control']);
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

    public function test_cache_control_is_preserved_in_tool_call_follow_up_requests(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = $this->anthropicCacheControlToolAgent()->prompt(
            'Generate a random number',
            provider: 'anthropic',
        );

        $this->assertSame('The number is 72019', $response->text);

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $firstBody = $recorded[0][0]->data();
        $this->assertSame([
            [
                'type' => 'text',
                'text' => 'You are a cacheable assistant that generates numbers.',
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ], $firstBody['system']);
        $this->assertArrayNotHasKey('cache_control', $firstBody);

        $secondBody = $recorded[1][0]->data();
        $this->assertSame($firstBody['system'], $secondBody['system']);
        $this->assertArrayNotHasKey('cache_control', $secondBody);
    }

    protected function anthropicCacheControlAgent(): Agent
    {
        return new class implements Agent, HasProviderOptions
        {
            use Promptable;

            public function instructions(): string
            {
                return 'You are a cacheable assistant.';
            }

            public function providerOptions(Lab|string $provider): array
            {
                $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

                return match ($provider) {
                    Lab::Anthropic => [
                        'cache_control' => ['type' => 'ephemeral'],
                    ],
                    default => [],
                };
            }
        };
    }

    protected function anthropicCacheControlToolAgent(): Agent
    {
        return new class implements Agent, HasProviderOptions, HasTools
        {
            use Promptable;

            public function instructions(): string
            {
                return 'You are a cacheable assistant that generates numbers.';
            }

            public function tools(): iterable
            {
                return [
                    new FixedNumberGenerator,
                ];
            }

            public function providerOptions(Lab|string $provider): array
            {
                $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

                return match ($provider) {
                    Lab::Anthropic => [
                        'cache_control' => ['type' => 'ephemeral'],
                    ],
                    default => [],
                };
            }
        };
    }
}
