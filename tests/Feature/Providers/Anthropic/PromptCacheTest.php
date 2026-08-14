<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\PromptCacheTarget;
use Tests\Fixtures\Agents\PromptCacheAgent;
use Tests\Fixtures\Agents\PromptCacheStructuredAgent;

beforeEach(function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);
});

test('system target converts instructions to a cached block', function (): void {
    (new PromptCacheAgent([PromptCacheTarget::System], withTools: false))->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $body['system'] === [[
            'type' => 'text',
            'text' => 'You are a helpful assistant that generates numbers.',
            'cache_control' => ['type' => 'ephemeral'],
        ]] && ! array_key_exists('prompt_cache', $body);
    });
});

test('tools target stamps the last tool', function (): void {
    (new PromptCacheAgent(['tools']))->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $body['system'] === 'You are a helpful assistant that generates numbers.'
            && Arr::last($body['tools'])['cache_control'] === ['type' => 'ephemeral'];
    });
});

test('both targets may be cached together', function (): void {
    (new PromptCacheAgent([PromptCacheTarget::System, PromptCacheTarget::Tools]))->prompt('Hi', provider: 'anthropic');

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

test('an empty prompt cache option leaves the payload untouched', function (): void {
    (new PromptCacheAgent)->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return is_string($body['system'])
            && ! array_key_exists('cache_control', Arr::last($body['tools']))
            && ! array_key_exists('prompt_cache', $body);
    });
});

test('other provider options still merge alongside the prompt cache option', function (): void {
    (new PromptCacheAgent([PromptCacheTarget::System], options: ['thinking' => ['type' => 'enabled', 'budget_tokens' => 10000]]))
        ->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $body['thinking']['budget_tokens'] === 10000
            && isset($body['system'][0]['cache_control'])
            && ! array_key_exists('prompt_cache', $body);
    });
});

test('a target may request the extended ttl', function (): void {
    (new PromptCacheAgent(['tools' => '5m', 'system' => '1h']))->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $body['system'][0]['cache_control'] === ['type' => 'ephemeral', 'ttl' => '1h']
            && Arr::last($body['tools'])['cache_control'] === ['type' => 'ephemeral', 'ttl' => '5m'];
    });
});

test('a target given true rather than a ttl uses the provider default', function (mixed $ttl): void {
    (new PromptCacheAgent(['system' => $ttl]))->prompt('Hi', provider: 'anthropic');

    Http::assertSent(fn ($request): bool => $request->data()['system'][0]['cache_control'] === ['type' => 'ephemeral']);
})->with([true, null]);

test('an unknown prompt cache target throws', function (): void {
    (new PromptCacheAgent(['messages']))->prompt('Hi', provider: 'anthropic');
})->throws(ValueError::class);

test('a falsy prompt cache option is a no-op rather than an error', function (mixed $cache): void {
    (new PromptCacheAgent($cache))->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return is_string($body['system'])
            && ! array_key_exists('cache_control', Arr::last($body['tools']));
    });
})->with([false, 0, '', null]);

test('breakpoints survive provider options that override the same keys', function (): void {
    (new PromptCacheAgent([PromptCacheTarget::System, PromptCacheTarget::Tools], options: [
        'system' => 'Overridden instructions.',
        'tools' => [['name' => 'custom', 'description' => '', 'input_schema' => ['type' => 'object']]],
    ]))->prompt('Hi', provider: 'anthropic');

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

    $this->collectStreamEvents(new PromptCacheAgent([PromptCacheTarget::System, PromptCacheTarget::Tools]));

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return isset($body['system'][0]['cache_control'])
            && Arr::last($body['tools'])['cache_control'] === ['type' => 'ephemeral'];
    });
});
