<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ProviderOptionsAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

test('provider options are included in generation config', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new ProviderOptionsAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );

    Http::assertSent(function ($request): bool {
        $body = $request->data();
        $config = $body['generationConfig'] ?? [];

        return isset($config['thinkingConfig'])
            && $config['thinkingConfig']['thinkingBudget'] === 10000;
    });
});

test('request body does not contain provider options when agent does not implement interface', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );

    Http::assertSent(function ($request): bool {
        $config = $request->data()['generationConfig'] ?? [];

        return ! isset($config['thinkingConfig']);
    });
});

test('provider options are persisted in tool call follow up requests', function (): void {
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

    expect($response->text)->toBe('The number is 72019');

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $firstConfig = $recorded[0][0]->data()['generationConfig'] ?? [];
    expect($firstConfig['thinkingConfig']['thinkingBudget'])->toBe(10000);

    $secondConfig = $recorded[1][0]->data()['generationConfig'] ?? [];
    expect($secondConfig)->toHaveKey('thinkingConfig')
        ->and($secondConfig['thinkingConfig']['thinkingBudget'])->toBe(10000);
});

test('cachedContent is placed at top level of request body, not in generationConfig', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    $agent = new class implements Agent, HasProviderOptions
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function providerOptions(Lab|string $provider): array
        {
            return match ($provider) {
                Lab::Gemini => ['cachedContent' => 'cachedContents/test-cache-123'],
                default => [],
            };
        }
    };

    $agent->prompt('Hi', provider: 'gemini');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return isset($body['cachedContent'])
            && $body['cachedContent'] === 'cachedContents/test-cache-123'
            && ! isset($body['generationConfig']['cachedContent']);
    });
});
