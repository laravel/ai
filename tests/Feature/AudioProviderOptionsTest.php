<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;
use Laravel\Ai\Prompts\QueuedAudioPrompt;
use Laravel\Ai\Providers\Provider;

beforeEach(function (): void {
    config(['ai.providers.openai' => [...config('ai.providers.openai'), 'key' => 'test-key']]);
});

test('provider options may not override the core audio request payload', function (): void {
    Http::fake(['*' => Http::response('fake-audio-bytes')]);

    Audio::of('Hello world')
        ->withProviderOptions(['model' => 'hijacked', 'stream_format' => 'sse'])
        ->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'gpt-4o-mini-tts' && $body['stream_format'] === 'sse';
    });
});

test('closure provider options receive the resolved audio provider', function (): void {
    Http::fake(['*' => Http::response('fake-audio-bytes')]);

    $seen = [];

    Audio::of('Hello world')
        ->withProviderOptions(function (Provider $provider) use (&$seen): array {
            $seen[] = $provider->driver();

            return ['stream_format' => 'sse'];
        })
        ->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    expect($seen)->toBe(['openai']);

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['stream_format'] === 'sse');
});

test('flat provider options are recorded on the queued audio prompt fake', function (): void {
    Audio::fake();

    Audio::of('Hello world')
        ->withProviderOptions(['stream_format' => 'sse'])
        ->queue(provider: 'openai');

    Audio::assertQueued(
        fn (QueuedAudioPrompt $prompt): bool => $prompt->providerOptions === ['stream_format' => 'sse'],
    );
});
