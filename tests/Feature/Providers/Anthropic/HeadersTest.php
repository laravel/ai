<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

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
            return ['ai_sdk_extra_headers' => ['anthropic-beta' => 'context-1m-2025-08-07']];
        }
    })->prompt('Hi', provider: 'anthropic');

    Http::assertSent(fn ($request): bool => $request->header('anthropic-beta') === ['context-1m-2025-08-07']);
});
