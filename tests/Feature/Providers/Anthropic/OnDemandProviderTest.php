<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Jobs\InvokeAgent;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\OnDemandProviderAgent;

test('prompts use an on-demand provider passed on its own', function (): void {
    Http::fake(['tenant.example.com/*' => $this->fakeTextResponse()]);

    (new AssistantAgent)->prompt('Hi', provider: Ai::build([
        'driver' => 'anthropic',
        'key' => 'tenant-key',
        'url' => 'https://tenant.example.com/v1',
    ]), model: 'claude-opus-5-5');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://tenant.example.com/v1/messages'
        && $request->header('x-api-key') === ['tenant-key']
        && $request['model'] === 'claude-opus-5-5');
});

test('prompts fail over between on-demand providers', function (): void {
    Http::fake([
        'primary.example.com/*' => Http::response([], 429),
        'backup.example.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt('Hi', provider: [
        Ai::build(['driver' => 'anthropic', 'key' => 'primary-key', 'url' => 'https://primary.example.com/v1']),
        Ai::build(['driver' => 'anthropic', 'key' => 'backup-key', 'url' => 'https://backup.example.com/v1']),
    ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://primary.example.com/v1/messages'
        && $request->header('x-api-key') === ['primary-key']);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://backup.example.com/v1/messages'
        && $request->header('x-api-key') === ['backup-key']);
});

test('an agent provider method rebuilds its on-demand provider on the queue worker', function (): void {
    Http::fake(['api.anthropic.com/*' => $this->fakeTextResponse()]);

    $job = unserialize(serialize(new InvokeAgent(new OnDemandProviderAgent('tenant-key'), 'Hi')));

    app()->forgetInstance(AiManager::class);
    Ai::clearResolvedInstances();

    $job->handle();

    Http::assertSent(fn ($request): bool => $request->header('x-api-key') === ['tenant-key']);
});

test('a queued prompt fails clearly when its on-demand provider was built at the call site', function (): void {
    $job = unserialize(serialize(new InvokeAgent(new AssistantAgent, 'Hi', provider: Ai::build([
        'driver' => 'anthropic',
        'key' => 'tenant-key',
    ]))));

    app()->forgetInstance(AiManager::class);
    Ai::clearResolvedInstances();

    $job->handle();
})->throws(InvalidArgumentException::class, 'was not built in this process');
