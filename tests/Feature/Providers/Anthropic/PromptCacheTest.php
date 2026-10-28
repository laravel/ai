<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Tests\Fixtures\Agents\PromptCacheAgent;
use Tests\Fixtures\Agents\PromptCacheStructuredAgent;

beforeEach(function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);
});

test('cache instructions attribute converts instructions to a cached block', function (): void {
    (new #[CacheInstructions] class(withTools: false) extends PromptCacheAgent {})->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $body['system'] === [[
            'type' => 'text',
            'text' => 'You are a helpful assistant that generates numbers.',
            'cache_control' => ['type' => 'ephemeral'],
        ]];
    });
});

test('cache tool definitions attribute stamps the last tool', function (): void {
    (new #[CacheToolDefinitions] class extends PromptCacheAgent {})->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $body['system'] === 'You are a helpful assistant that generates numbers.'
            && Arr::last($body['tools'])['cache_control'] === ['type' => 'ephemeral'];
    });
});

test('both targets may be cached together', function (): void {
    (new #[CacheInstructions] #[CacheToolDefinitions] class extends PromptCacheAgent {})->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return isset($body['system'][0]['cache_control'])
            && Arr::last($body['tools'])['cache_control'] === ['type' => 'ephemeral'];
    });
});

test('the synthetic structured output tool receives the breakpoint', function (): void {
    config(['ai.providers.anthropic' => [
        ...config('ai.providers.anthropic'),
        'use_native_structured_output' => false,
    ]]);

    Http::fake([
        'api.anthropic.com/*' => $this->fakeSyntheticStructuredResponse(['symbol' => 'Fe']),
    ]);

    (new PromptCacheStructuredAgent)->prompt('Iron', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return Arr::last($body['tools'])['name'] === 'output_structured_data'
            && Arr::last($body['tools'])['cache_control'] === ['type' => 'ephemeral'];
    });
});

test('an agent without cache attributes leaves the payload untouched', function (): void {
    (new PromptCacheAgent)->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return is_string($body['system'])
            && ! array_key_exists('cache_control', Arr::last($body['tools']));
    });
});

test('provider options still merge alongside cache attributes', function (): void {
    (new #[CacheInstructions] class(options: ['thinking' => ['type' => 'enabled', 'budget_tokens' => 10000]]) extends PromptCacheAgent {})
        ->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $body['thinking']['budget_tokens'] === 10000
            && isset($body['system'][0]['cache_control']);
    });
});

test('a target may request the extended ttl', function (): void {
    (new #[CacheInstructions('5m')] #[CacheToolDefinitions('1h')] class extends PromptCacheAgent {})->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $body['system'][0]['cache_control'] === ['type' => 'ephemeral', 'ttl' => '5m']
            && Arr::last($body['tools'])['cache_control'] === ['type' => 'ephemeral', 'ttl' => '1h'];
    });
});

test('an omitted ttl uses the provider default', function (): void {
    (new #[CacheInstructions] class extends PromptCacheAgent {})->prompt('Hi', provider: 'anthropic');

    Http::assertSent(fn ($request): bool => $request->data()['system'][0]['cache_control'] === ['type' => 'ephemeral']);
});

test('a longer instructions ttl requires the tools cache to use the same ttl', function (): void {
    (new #[CacheInstructions('1h')] #[CacheToolDefinitions('5m')] class extends PromptCacheAgent {})->prompt('Hi', provider: 'anthropic');
})->throws(InvalidArgumentException::class);

test('a longer automatic cache ttl requires explicit breakpoints to use the same ttl', function (): void {
    (new #[CacheInstructions] class(options: ['cache_control' => ['type' => 'ephemeral', 'ttl' => '1h']]) extends PromptCacheAgent {})->prompt('Hi', provider: 'anthropic');
})->throws(InvalidArgumentException::class);

test('breakpoints survive provider options that override the same keys', function (): void {
    (new #[CacheInstructions] #[CacheToolDefinitions] class(options: ['system' => 'Overridden instructions.', 'tools' => [['name' => 'custom', 'description' => '', 'input_schema' => ['type' => 'object']]]]) extends PromptCacheAgent {})->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $body['system'] === [[
            'type' => 'text',
            'text' => 'Overridden instructions.',
            'cache_control' => ['type' => 'ephemeral'],
        ]]
            && Arr::last($body['tools'])['name'] === 'custom'
            && Arr::last($body['tools'])['cache_control'] === ['type' => 'ephemeral'];
    });
});

test('the tools target is a no-op when the request carries no tools', function (): void {
    (new PromptCacheStructuredAgent)->prompt('Iron', provider: 'anthropic');

    Http::assertSent(fn ($request): bool => ! array_key_exists('tools', $request->data()));
});

test('the streaming path stamps the same breakpoints', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response($this->ssePayload([
            $this->messageStart(),
            $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
            $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hi']),
            $this->contentBlockStop(0),
            $this->messageDelta('end_turn', 5),
        ]), 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $this->collectStreamEvents(new #[CacheInstructions] #[CacheToolDefinitions] class extends PromptCacheAgent {});

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return isset($body['system'][0]['cache_control'])
            && Arr::last($body['tools'])['cache_control'] === ['type' => 'ephemeral'];
    });
});
