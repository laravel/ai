<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;
use Laravel\Ai\Jobs\GenerateAudio;
use Laravel\Ai\Prompts\QueuedAudioPrompt;
use Laravel\Ai\Providers\Provider;

beforeEach(function (): void {
    config(['ai.providers.openai' => [...config('ai.providers.openai'), 'key' => 'test-key']]);
});

test('provider options may not override the core audio request payload', function (): void {
    Http::fake(['*' => Http::response('fake-audio-bytes')]);

    Audio::of('Hello world')
        ->withProviderOptions(['model' => 'hijacked', 'speed' => 0.8])
        ->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'gpt-4o-mini-tts' && $body['speed'] === 0.8;
    });
});

test('closure provider options receive the resolved audio provider', function (): void {
    Http::fake(['*' => Http::response('fake-audio-bytes')]);

    $seen = [];

    Audio::of('Hello world')
        ->withProviderOptions(function (Provider $provider) use (&$seen): array {
            $seen[] = $provider->driver();

            return ['speed' => 0.8];
        })
        ->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    expect($seen)->toBe(['openai']);

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['speed'] === 0.8);
});

test('flat provider options are recorded on the queued audio prompt fake', function (): void {
    Audio::fake();

    Audio::of('Hello world')
        ->withProviderOptions(['speed' => 0.8])
        ->queue(provider: 'openai');

    Audio::assertQueued(
        fn (QueuedAudioPrompt $prompt): bool => $prompt->providerOptions === ['speed' => 0.8],
    );
});

test('falsy provider options are not dropped from the audio request', function (): void {
    Http::fake(['*' => Http::response('fake-audio-bytes')]);

    Audio::of('Hello world')
        ->withProviderOptions(['stream' => false])
        ->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return array_key_exists('stream', $body) && $body['stream'] === false;
    });
});

test('closure provider options survive queue serialization round-trip', function (): void {
    Http::fake(['*' => Http::response('fake-audio-bytes')]);

    $job = new GenerateAudio(
        Audio::of('Hello world')->withProviderOptions(fn (Provider $provider): array => ['speed' => 0.8]),
        'openai',
        'gpt-4o-mini-tts',
    );

    unserialize(serialize($job))->handle();

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['speed'] === 0.8);
});
