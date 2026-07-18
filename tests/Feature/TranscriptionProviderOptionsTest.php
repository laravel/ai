<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Jobs\GenerateTranscription;
use Laravel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Laravel\Ai\Prompts\QueuedTranscriptionPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Transcription;

beforeEach(function (): void {
    config([
        'ai.providers.openai' => [...config('ai.providers.openai'), 'key' => 'test-key'],
        'ai.providers.mistral' => [...config('ai.providers.mistral'), 'key' => 'test-key'],
    ]);
});

test('flat provider options are sent on the transcription request', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Hello', 'usage' => ['input_tokens' => 1, 'total_tokens' => 2]])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(['prompt' => 'Laravel Forge and Vapor'])
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Laravel Forge and Vapor'));
});

test('closure resolver receives the resolved provider and applies per-provider options', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['text' => 'Hello', 'usage' => ['input_tokens' => 1, 'total_tokens' => 2]]),
        'api.mistral.ai/*' => Http::response(['text' => 'Hello', 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]),
    ]);

    $seen = [];

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(function (Provider $provider) use (&$seen): array {
            $seen[] = $provider->driver();

            return $provider->driver() === 'openai'
                ? ['prompt' => 'OpenAI hint']
                : ['context_bias' => 'Mistral,hint'];
        })
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

    expect($seen)->toBe(['openai']);

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'OpenAI hint'));
});

test('closure provider options are not recorded on the queued prompt fake', function (): void {
    Transcription::fake();

    Transcription::fromPath('/path/to/audio.mp3')
        ->withProviderOptions(fn (Provider $provider): array => ['prompt' => 'hint'])
        ->queue(provider: 'openai');

    Transcription::assertQueued(
        fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->providerOptions === [],
    );
});

test('closure provider options survive queue serialization round-trip', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['text' => 'Hello', 'usage' => ['input_tokens' => 1, 'total_tokens' => 2]]),
    ]);

    $pending = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(fn (Provider $provider): array => ['prompt' => 'Serialized hint']);

    $job = new GenerateTranscription($pending, 'openai', 'gpt-4o-transcribe');

    $restored = unserialize(serialize($job));

    expect($restored->pendingTranscription)->toBeInstanceOf(PendingTranscriptionGeneration::class);

    $restored->handle();

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Serialized hint'));
});

test('closure resolver returning null is treated as no options', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['text' => 'Hello', 'usage' => ['input_tokens' => 1, 'total_tokens' => 2]]),
    ]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(fn (): null => null)
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'prompt'));
});
