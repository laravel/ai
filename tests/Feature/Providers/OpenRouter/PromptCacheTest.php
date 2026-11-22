<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Attributes\CacheInstructions;
use Tests\Fixtures\Agents\PromptCacheAgent;

beforeEach(function (): void {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);

    Http::fake(['*' => fakeOpenRouterResponse('Hello')]);
});

test('cache instructions attribute converts the system message to a cached block', function (): void {
    (new #[CacheInstructions] class(withTools: false) extends PromptCacheAgent {})->prompt('Hi', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $body['messages'][0] === [
            'role' => 'system',
            'content' => [[
                'type' => 'text',
                'text' => 'You are a helpful assistant that generates numbers.',
                'cache_control' => ['type' => 'ephemeral'],
            ]],
        ] && $body['messages'][1]['content'] === 'Hi';
    });
});

test('a requested ttl is added to the cache control', function (): void {
    (new #[CacheInstructions('1h')] class(withTools: false) extends PromptCacheAgent {})->prompt('Hi', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        return $request->data()['messages'][0]['content'][0]['cache_control'] === ['type' => 'ephemeral', 'ttl' => '1h'];
    });
});

test('an agent without the attribute keeps the plain system message', function (): void {
    (new PromptCacheAgent(withTools: false))->prompt('Hi', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        return $request->data()['messages'][0] === [
            'role' => 'system',
            'content' => 'You are a helpful assistant that generates numbers.',
        ];
    });
});

test('the breakpoint survives provider options that override the messages', function (): void {
    (new #[CacheInstructions] class(withTools: false, options: ['messages' => [['role' => 'system', 'content' => [['type' => 'text', 'text' => 'Static.'], ['type' => 'text', 'text' => 'Overridden.']]], ['role' => 'user', 'content' => 'Hi']]]) extends PromptCacheAgent {})->prompt('Hi', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $system = $request->data()['messages'][0]['content'];

        return ! isset($system[0]['cache_control'])
            && $system[1]['cache_control'] === ['type' => 'ephemeral'];
    });
});
