<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\PromptCacheTarget;
use Tests\Fixtures\Agents\PromptCacheAgent;
use Tests\Fixtures\Agents\PromptCacheStructuredAgent;

$ephemeral = ['type' => 'ephemeral'];

test('system target sends instructions as a cached text block', function () use ($ephemeral): void {
    Http::fake(['api.anthropic.com/*' => $this->fakeTextResponse()]);

    (new PromptCacheAgent(cache: [PromptCacheTarget::System], withTools: false))->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request) use ($ephemeral): bool {
        $body = $request->data();

        return $body['system'] === [[
            'type' => 'text',
            'text' => 'You are a helpful assistant.',
            'cache_control' => $ephemeral,
        ]] && ! array_key_exists('prompt_cache', $body);
    });
});

test('tools target stamps the last tool only', function () use ($ephemeral): void {
    Http::fake(['api.anthropic.com/*' => $this->fakeTextResponse()]);

    (new PromptCacheAgent(cache: ['tools']))->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request) use ($ephemeral): bool {
        $body = $request->data();
        $tools = $body['tools'];

        return is_string($body['system'])
            && $tools[array_key_last($tools)]['cache_control'] === $ephemeral;
    });
});

test('both targets may be combined with other provider options', function () use ($ephemeral): void {
    Http::fake(['api.anthropic.com/*' => $this->fakeTextResponse()]);

    (new PromptCacheAgent(
        cache: [PromptCacheTarget::System, PromptCacheTarget::Tools],
        otherOptions: ['cache_control' => ['type' => 'ephemeral']],
    ))->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request) use ($ephemeral): bool {
        $body = $request->data();

        return $body['system'][0]['cache_control'] === $ephemeral
            && $body['tools'][array_key_last($body['tools'])]['cache_control'] === $ephemeral
            && $body['cache_control'] === $ephemeral
            && ! array_key_exists('prompt_cache', $body);
    });
});

test('synthetic structured output tool receives the tools breakpoint', function () use ($ephemeral): void {
    config(['ai.providers.anthropic' => [
        ...config('ai.providers.anthropic'),
        'use_native_structured_output' => false,
    ]]);

    Http::fake(['api.anthropic.com/*' => $this->fakeSyntheticStructuredResponse(['number' => 42])]);

    (new PromptCacheStructuredAgent(cache: ['tools']))->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request) use ($ephemeral): bool {
        $tools = $request->data()['tools'];

        return $tools[array_key_last($tools)]['name'] === 'output_structured_data'
            && $tools[array_key_last($tools)]['cache_control'] === $ephemeral
            && ! isset($tools[0]['cache_control']);
    });
});

test('empty prompt cache leaves the payload untouched', function (): void {
    Http::fake(['api.anthropic.com/*' => $this->fakeTextResponse()]);

    (new PromptCacheAgent(cache: []))->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return is_string($body['system'])
            && ! isset($body['tools'][0]['cache_control'])
            && ! array_key_exists('prompt_cache', $body);
    });
});

test('unknown prompt cache target throws', function (): void {
    Http::fake(['api.anthropic.com/*' => $this->fakeTextResponse()]);

    (new PromptCacheAgent(cache: ['systemm']))->prompt('Hi', provider: 'anthropic');
})->throws(ValueError::class);
