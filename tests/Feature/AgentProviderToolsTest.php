<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ProviderToolsAgent;

test('agent tools receive the lab of the provider being invoked', function (): void {
    Http::fake(['api.anthropic.com/*' => fakeAnthropicResponse()]);

    (new ProviderToolsAgent)->prompt('Fetch something', provider: 'anthropic');

    Http::assertSent(function (Request $request): bool {
        $names = collect($request->data()['tools'] ?? [])->pluck('name')->all();

        return in_array('web_fetch', $names, true)
            && in_array('FixedNumberGenerator', $names, true);
    });
});

test('provider specific tools are omitted when another provider is invoked', function (): void {
    Http::fake(['api.openai.com/*' => fakeOpenAiResponse()]);

    (new ProviderToolsAgent)->prompt('Fetch something', provider: 'openai');

    Http::assertSent(function (Request $request): bool {
        $tools = collect($request->data()['tools'] ?? []);

        return $tools->count() === 1
            && $tools->pluck('name')->all() === ['FixedNumberGenerator'];
    });
});

test('agent tools receive the lab of the provider rather than its connection name', function (): void {
    config(['ai.providers.primary' => ['driver' => 'anthropic', 'key' => 'test-key']]);

    Http::fake(['api.anthropic.com/*' => fakeAnthropicResponse()]);

    (new ProviderToolsAgent)->prompt('Fetch something', provider: 'primary');

    Http::assertSent(function (Request $request): bool {
        $names = collect($request->data()['tools'] ?? [])->pluck('name')->all();

        return in_array('web_fetch', $names, true);
    });
});

test('tools are resolved again for each provider during failover', function (): void {
    config([
        'ai.providers.primary' => ['driver' => 'anthropic', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'openai', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fake([
        'api.anthropic.com/*' => Http::response(status: 429),
        'api.openai.com/*' => fakeOpenAiResponse('Hello'),
    ]);

    $response = (new ProviderToolsAgent)->prompt(
        'Fetch something',
        provider: ['primary', 'backup'],
    );

    expect($response->text)->toBe('Hello');

    Http::assertSent(function (Request $request): bool {
        $names = collect($request->data()['tools'] ?? [])->pluck('name')->all();

        return str_contains($request->url(), 'api.anthropic.com')
            && in_array('web_fetch', $names, true);
    });

    Http::assertSent(function (Request $request): bool {
        $names = collect($request->data()['tools'] ?? [])->pluck('name')->all();

        return str_contains($request->url(), 'api.openai.com')
            && $names === ['FixedNumberGenerator'];
    });
});
