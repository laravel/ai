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

test('an unknown prompt cache target throws', function (): void {
    (new PromptCacheAgent(['messages']))->prompt('Hi', provider: 'anthropic');
})->throws(ValueError::class);
