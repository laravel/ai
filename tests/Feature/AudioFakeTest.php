<?php

use Illuminate\Support\Str;
use Laravel\Ai\Audio;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\AudioPrompt;
use Laravel\Ai\Prompts\QueuedAudioPrompt;
use Laravel\Ai\Providers\InfomaniakProvider;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;

test('audio can be faked', function () {
    Audio::fake([
        base64_encode('first-audio'),
        fn (AudioPrompt $prompt) => base64_encode('second-audio-'.$prompt->text),
        new AudioResponse(base64_encode('third-audio'), new Meta),
    ]);

    $response = Audio::of('First text')->generate();
    expect($response->audio)->toEqual(base64_encode('first-audio'));

    $response = Audio::of('Second text')->generate();
    expect($response->audio)->toEqual(base64_encode('second-audio-Second text'));

    $response = Audio::of('Third text')->generate();
    expect($response->audio)->toEqual(base64_encode('third-audio'));

    // Assertion tests...
    Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->text === 'First text');
    Audio::assertNotGenerated(fn (AudioPrompt $prompt) => $prompt->text === 'Missing text');

    Audio::assertGenerated(function (AudioPrompt $prompt) {
        return $prompt->text === 'First text';
    });
});

test('can assert no audio was generated', function () {
    Audio::fake();

    Audio::assertNothingGenerated();
});

test('audio can be faked with no predefined responses', function () {
    Audio::fake();

    $response = Audio::of('First text')->generate();
    expect($response->audio)->toEqual(base64_encode('fake-audio-content'));

    $response = Audio::of('Second text')->generate();
    expect($response->audio)->toEqual(base64_encode('fake-audio-content'));
});

test('audio can be faked with a single closure that is invoked for every generation', function () {
    Audio::fake(function (AudioPrompt $prompt) {
        return base64_encode('audio-for-'.$prompt->text);
    });

    $response = Audio::of('First text')->generate();
    expect($response->audio)->toEqual(base64_encode('audio-for-First text'));

    $response = Audio::of('Second text')->generate();
    expect($response->audio)->toEqual(base64_encode('audio-for-Second text'));
});

test('audio timeout defaults to sdk fallback', function () {
    Audio::fake();

    Audio::of('Hello world')->generate();

    Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->timeout === 30);
});

test('fake audio closure receives timeout', function () {
    Audio::fake(function (AudioPrompt $prompt) {
        expect($prompt->timeout)->toBe(45);

        return base64_encode('audio-for-'.$prompt->text);
    });

    Audio::of('Hello world')->timeout(45)->generate();
});

test('audio can be generated from stringable macro', function () {
    Audio::fake();

    $response = Str::of('Hello world')->toAudio();

    expect($response->audio)->toEqual(base64_encode('fake-audio-content'));

    Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->text === 'Hello world');
});

test('stringable audio macro passes through options', function () {
    $this->markTestIncomplete('Macro provider resolution needs investigation');
});

test('audio can prevent stray generations', function () {
    Audio::fake()->preventStrayAudio();

    Audio::of('First text')->generate();
})->throws(RuntimeException::class);

test('fake closures can throw exceptions', function () {
    Audio::fake(function () {
        throw new Exception('Something went wrong');
    });

    Audio::of('Test text')->generate();
})->throws(Exception::class);

test('audio voice and instructions are recorded', function () {
    Audio::fake();

    Audio::of('Hello world')->voice('alloy')->instructions('Speak slowly')->generate();

    Audio::assertGenerated(function (AudioPrompt $prompt) {
        return $prompt->text === 'Hello world'
            && $prompt->provider instanceof InfomaniakProvider
            && $prompt->voice === 'alloy'
            && $prompt->instructions === 'Speak slowly';
    });
});

test('queued audio can be faked', function () {
    Audio::fake();

    Audio::of('First text')->queue();

    Audio::assertQueued(fn (QueuedAudioPrompt $prompt) => $prompt->text === 'First text');
    Audio::assertNotQueued(fn (QueuedAudioPrompt $prompt) => $prompt->contains('Second text'));

    Audio::assertQueued(function (QueuedAudioPrompt $prompt) {
        return $prompt->text === 'First text';
    });

    Audio::assertNotQueued(function (QueuedAudioPrompt $prompt) {
        return $prompt->text === 'Second text';
    });
});

test('can assert no audio was queued', function () {
    Audio::fake();

    Audio::assertNothingQueued();
});

test('generate accepts ai provider enum', function () {
    Audio::fake();

    Audio::of('Enum audio')->generate(provider: Lab::OpenAI);

    Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->text === 'Enum audio');
});

test('queued audio accepts ai provider enum', function () {
    Audio::fake();

    Audio::of('Queued enum audio')->queue(provider: Lab::OpenAI);

    Audio::assertQueued(fn (QueuedAudioPrompt $prompt) => $prompt->text === 'Queued enum audio'
        && $prompt->provider === Lab::OpenAI);
});

test('queued audio voice and instructions are recorded', function () {
    Audio::fake();

    Audio::of('Hello world')->male()->instructions('Speak quickly')->queue();

    Audio::assertQueued(function (QueuedAudioPrompt $prompt) {
        return $prompt->text === 'Hello world'
            && $prompt->voice === 'default-male'
            && $prompt->instructions === 'Speak quickly';
    });
});

test('queued audio timeout is recorded', function () {
    Audio::fake();

    Audio::of('Hello world')->timeout(90)->queue();

    Audio::assertQueued(function (QueuedAudioPrompt $prompt) {
        return $prompt->timeout === 90 && $prompt->contains('Hello world');
    });
});
