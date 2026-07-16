<?php

use Illuminate\Support\Str;
use Laravel\Ai\Audio;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\AudioPrompt;
use Laravel\Ai\Prompts\QueuedAudioPrompt;
use Laravel\Ai\Providers\ElevenLabsProvider;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;

test('audio rejects empty text', function (): void {
    Audio::fake();

    Audio::of('')->generate();
})->throws(InvalidArgumentException::class, 'Text content is required to generate audio.');

test('audio rejects whitespace-only text', function (): void {
    Audio::fake();

    Audio::of(" \t\n")->generate();
})->throws(InvalidArgumentException::class, 'Text content is required to generate audio.');

test('audio can be faked', function (): void {
    Audio::fake([
        base64_encode('first-audio'),
        fn (AudioPrompt $prompt): string => base64_encode('second-audio-'.$prompt->text),
        new AudioResponse(base64_encode('third-audio'), new Meta),
    ]);

    $response = Audio::of('First text')->generate();
    expect($response->audio)->toEqual(base64_encode('first-audio'));

    $response = Audio::of('Second text')->generate();
    expect($response->audio)->toEqual(base64_encode('second-audio-Second text'));

    $response = Audio::of('Third text')->generate();
    expect($response->audio)->toEqual(base64_encode('third-audio'));

    // Assertion tests...
    Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->text === 'First text');
    Audio::assertNotGenerated(fn (AudioPrompt $prompt): bool => $prompt->text === 'Missing text');

    Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->text === 'First text');
});

test('can assert no audio was generated', function (): void {
    Audio::fake();

    Audio::assertNothingGenerated();
});

test('audio can be faked with no predefined responses', function (): void {
    Audio::fake();

    $response = Audio::of('First text')->generate();
    expect($response->audio)->toEqual(base64_encode('fake-audio-content'));

    $response = Audio::of('Second text')->generate();
    expect($response->audio)->toEqual(base64_encode('fake-audio-content'));
});

test('audio can be faked with a single closure that is invoked for every generation', function (): void {
    Audio::fake(fn (AudioPrompt $prompt): string => base64_encode('audio-for-'.$prompt->text));

    $response = Audio::of('First text')->generate();
    expect($response->audio)->toEqual(base64_encode('audio-for-First text'));

    $response = Audio::of('Second text')->generate();
    expect($response->audio)->toEqual(base64_encode('audio-for-Second text'));
});

test('audio timeout defaults to sdk fallback', function (): void {
    Audio::fake();

    Audio::of('Hello world')->generate();

    Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->timeout === 30);
});

test('fake audio closure receives timeout', function (): void {
    Audio::fake(function (AudioPrompt $prompt): string {
        expect($prompt->timeout)->toBe(45);

        return base64_encode('audio-for-'.$prompt->text);
    });

    Audio::of('Hello world')->timeout(45)->generate();
});

test('audio can be generated from stringable macro', function (): void {
    Audio::fake();

    $response = Str::of('Hello world')->toAudio();

    expect($response->audio)->toEqual(base64_encode('fake-audio-content'));

    Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->text === 'Hello world');
});

test('stringable audio macro passes through options', function (): void {
    Audio::fake();

    Str::of('Hello world')->toAudio(
        provider: Lab::ElevenLabs,
        voice: 'alloy',
        instructions: 'Speak slowly',
        model: 'custom-model',
        timeout: 45,
    );

    Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->text === 'Hello world'
        && $prompt->provider instanceof ElevenLabsProvider
        && $prompt->voice === 'alloy'
        && $prompt->instructions === 'Speak slowly'
        && $prompt->model === 'custom-model'
        && $prompt->timeout === 45);
});

test('audio can prevent stray generations', function (): void {
    Audio::fake()->preventStrayAudio();

    Audio::of('First text')->generate();
})->throws(RuntimeException::class);

test('fake closures can throw exceptions', function (): void {
    Audio::fake(function (): void {
        throw new Exception('Something went wrong');
    });

    Audio::of('Test text')->generate();
})->throws(Exception::class);

test('audio voice and instructions are recorded', function (): void {
    Audio::fake();

    Audio::of('Hello world')->voice('alloy')->instructions('Speak slowly')->generate();

    Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->text === 'Hello world'
        && $prompt->voice === 'alloy'
        && $prompt->instructions === 'Speak slowly');
});

test('queued audio can be faked', function (): void {
    Audio::fake();

    Audio::of('First text')->queue();

    Audio::assertQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'First text');
    Audio::assertNotQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->contains('Second text'));

    Audio::assertQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'First text');

    Audio::assertNotQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'Second text');
});

test('can assert no audio was queued', function (): void {
    Audio::fake();

    Audio::assertNothingQueued();
});

test('generate accepts ai provider enum', function (): void {
    Audio::fake();

    Audio::of('Enum audio')->generate(provider: Lab::OpenAI);

    Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->text === 'Enum audio');
});

test('queued audio accepts ai provider enum', function (): void {
    Audio::fake();

    Audio::of('Queued enum audio')->queue(provider: Lab::ElevenLabs);

    Audio::assertQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'Queued enum audio'
        && $prompt->provider === Lab::ElevenLabs);
});

test('queued audio voice and instructions are recorded', function (): void {
    Audio::fake();

    Audio::of('Hello world')->male()->instructions('Speak quickly')->queue();

    Audio::assertQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'Hello world'
        && $prompt->voice === 'default-male'
        && $prompt->instructions === 'Speak quickly');
});

test('queued audio timeout is recorded', function (): void {
    Audio::fake();

    Audio::of('Hello world')->timeout(90)->queue();

    Audio::assertQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->timeout === 90 && $prompt->contains('Hello world'));
});
