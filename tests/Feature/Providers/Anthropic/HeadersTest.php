<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\HeadersAgent;

test('custom headers are included in anthropic request', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new HeadersAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $request->hasHeader('X-Custom-Header', 'anthropic-value')
            && ! array_key_exists('X-Custom-Header', $body);
    });
});

test('request does not contain custom headers when agent does not implement interface', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(fn ($request): bool => ! $request->hasHeader('X-Custom-Header'));
});

test('agent headers replace headers the sdk already sets', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new class implements Agent, HasProviderOptions
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function providerOptions(Lab|string $provider): array
        {
            return ['extra_headers' => ['anthropic-beta' => 'context-1m-2025-08-07']];
        }
    })->prompt('Hi', provider: 'anthropic');

    Http::assertSent(fn ($request): bool => $request->header('anthropic-beta') === ['context-1m-2025-08-07']);
});
