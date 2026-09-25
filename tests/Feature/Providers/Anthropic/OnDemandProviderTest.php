<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Jobs\InvokeAgent;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\OnDemandProviderAgent;

test('prompts can use an on-demand provider', function (): void {
    Http::fake(['tenant-proxy.example.com/*' => $this->fakeTextResponse()]);

    (new AssistantAgent)->prompt('Hi', provider: [
        Ai::build(['driver' => 'anthropic', 'key' => 'tenant-key', 'url' => 'https://tenant-proxy.example.com/v1']),
        'openai',
    ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://tenant-proxy.example.com/v1/messages'
        && $request->header('x-api-key') === ['tenant-key']);
});

test('on-demand providers with different config never share an instance', function (): void {
    $first = Ai::build(['driver' => 'anthropic', 'key' => 'tenant-a']);
    $second = Ai::build(['driver' => 'anthropic', 'key' => 'tenant-b']);

    expect($first->name())->not->toBe($second->name())
        ->and($first->providerCredentials()['key'])->toBe('tenant-a')
        ->and($second->providerCredentials()['key'])->toBe('tenant-b');
});

test('an agent provider method rebuilds its on-demand provider on the queue worker', function (): void {
    Http::fake(['api.anthropic.com/*' => $this->fakeTextResponse()]);

    $job = unserialize(serialize(new InvokeAgent(new OnDemandProviderAgent('tenant-key'), 'Hi')));

    app()->forgetInstance(AiManager::class);
    Ai::clearResolvedInstances();

    $job->handle();

    Http::assertSent(fn ($request): bool => $request->header('x-api-key') === ['tenant-key']);
});
