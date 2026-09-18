<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);

    $this->customUrl = 'http://localhost:1234/v1';
});

test('mistral requests use the configured base url', function (): void {
    configureMistralProvider($this->customUrl);

    Http::fake([
        '*' => $this->fakeTextResponse('Hello from custom'),
    ]);

    $response = agent()->prompt('Hello', provider: 'mistral');

    expect($response->text)->toBe('Hello from custom');

    Http::assertSentCount(1);
    mistralAssertRequestSent('POST', "{$this->customUrl}/chat/completions");
});

test('mistral requests fall back to the default base url', function (): void {
    Http::fake([
        '*' => $this->fakeTextResponse('Hello from Mistral'),
    ]);

    $response = agent()->prompt('Hello', provider: 'mistral');

    expect($response->text)->toBe('Hello from Mistral');

    Http::assertSentCount(1);
    mistralAssertRequestSent('POST', 'https://api.mistral.ai/v1/chat/completions');
});

test('mistral audio requests use the configured base url', function (): void {
    configureMistralProvider($this->customUrl);

    Http::fake([
        '*' => Http::response(['audio_data' => base64_encode('fake-audio-bytes')]),
    ]);

    Audio::of('Hello')->generate(provider: 'mistral');

    Http::assertSentCount(1);
    mistralAssertRequestSent('POST', "{$this->customUrl}/audio/speech");
});

function configureMistralProvider(?string $url = null): void
{
    config(['ai.providers.mistral' => array_filter([
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
        'url' => $url,
    ])]);
}

function mistralAssertRequestSent(string $method, string $url): void
{
    Http::assertSent(fn (Request $request): bool => $request->method() === $method
        && $request->url() === $url);
}
