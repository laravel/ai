<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Laravel\Ai\agent;

test('workersai builds direct url from account_id', function () {
    configureWorkersAiProvider(accountId: 'test-account-123');

    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.cloudflare.com/client/v4/accounts/test-account-123/ai/v1/chat/completions');
});

test('workersai builds gateway url when gateway config is set', function () {
    configureWorkersAiProvider(accountId: 'test-account-123', gateway: 'my-gateway');

    Http::fake(['gateway.ai.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://gateway.ai.cloudflare.com/v1/test-account-123/my-gateway/workers-ai/v1/chat/completions');
});

test('workersai uses explicit url when set', function () {
    configureWorkersAiProvider(url: 'http://localhost:8787/v1');

    Http::fake(['localhost:8787/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->url() === 'http://localhost:8787/v1/chat/completions');
});

test('workersai explicit url overrides account_id and gateway', function () {
    configureWorkersAiProvider(
        accountId: 'test-account-123',
        gateway: 'my-gateway',
        url: 'http://custom.example.com/v1',
    );

    Http::fake(['custom.example.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->url() === 'http://custom.example.com/v1/chat/completions');
});

test('workersai throws when account_id is missing and no url set', function () {
    configureWorkersAiProvider();

    agent()->prompt('Hello', provider: 'workersai');
})->throws(\Laravel\Ai\Exceptions\AiException::class, 'account_id');

test('workersai throws when compat url used without model prefix', function () {
    configureWorkersAiProvider(url: 'https://gateway.ai.cloudflare.com/v1/abc/gw/compat');

    agent()->prompt('Hello', provider: 'workersai');
})->throws(\Laravel\Ai\Exceptions\AiException::class, "requires the 'workers-ai/' prefix");

test('workersai sends bearer token in authorization header', function () {
    configureWorkersAiProvider(accountId: 'test-123');

    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer test-key'));
});

test('workersai sends session affinity header when configured', function () {
    configureWorkersAiProvider(accountId: 'test-123', sessionAffinity: 'ses_abc123');

    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->hasHeader('x-session-affinity', 'ses_abc123'));
});

function configureWorkersAiProvider(
    ?string $accountId = null,
    ?string $gateway = null,
    ?string $url = null,
    ?string $sessionAffinity = null,
): void {
    config(['ai.providers.workersai' => array_filter([
        'driver' => 'workersai',
        'key' => 'test-key',
        'account_id' => $accountId,
        'gateway' => $gateway,
        'url' => $url,
        'session_affinity' => $sessionAffinity,
    ])]);
}

