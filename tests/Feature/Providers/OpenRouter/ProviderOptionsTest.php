<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Agents\ProviderOptionsAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

test('provider options are included in openrouter request body', function () {
    Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

    (new ProviderOptionsAgent)->prompt('Hello', provider: 'openrouter');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'frequency_penalty') === 0.5
            && data_get($body, 'presence_penalty') === 0.3;
    });
});

test('request body does not contain provider options when agent does not implement interface', function () {
    Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openrouter');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('frequency_penalty', $body)
            && ! array_key_exists('presence_penalty', $body);
    });
});

test('agents using the docs idiom (no Lab::tryFrom workaround) have provider options reach the openrouter body', function () {
    Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

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
                Lab::OpenRouter => ['reasoning' => ['effort' => 'medium']],
                default => [],
            };
        }
    };

    $agent->prompt('Hello', provider: 'openrouter');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'reasoning.effort') === 'medium';
    });
});

test('provider options are persisted in tool call follow up requests', function () {
    Http::fake([
        '*' => Http::sequence([
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterResponse('The number is 72019'),
        ]),
    ]);

    (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'openrouter');

    $requests = Http::recorded(fn (Request $r) => true);

    expect(count($requests))->toBeGreaterThanOrEqual(2);

    $followUpBody = json_decode($requests[1][0]->body(), true);

    expect(data_get($followUpBody, 'frequency_penalty'))->toBe(0.5);
});
