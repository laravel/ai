<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\QueuedTranscriptionPrompt;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;

test('transcriptions can be faked', function () {
    Transcription::fake([
        'First transcription',
        fn (TranscriptionPrompt $prompt) => 'Second transcription',
        new TranscriptionResponse(
            'Third transcription',
            new Collection([new TranscriptionSegment('Third transcription', 'Speaker 1', 0.0, 1.0)]),
            new Usage,
            new Meta,
        ),
    ]);

    $response = Transcription::of(base64_encode('audio-1'))->generate();
    expect($response->text)->toEqual('First transcription');

    $response = Transcription::of(base64_encode('audio-2'))->generate();
    expect($response->text)->toEqual('Second transcription');

    $response = Transcription::of(base64_encode('audio-3'))->generate();
    expect($response->text)->toEqual('Third transcription');

    // Assertion tests...
    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt) => true);
    Transcription::assertNotGenerated(fn (TranscriptionPrompt $prompt) => $prompt->language === 'fr');
});

test('can assert no transcriptions were generated', function () {
    Transcription::fake();

    Transcription::assertNothingGenerated();
});

test('transcriptions can be faked with no predefined responses', function () {
    Transcription::fake();

    $response = Transcription::of(base64_encode('audio-1'))->generate();
    expect($response->text)->toEqual('Fake transcription text.');

    $response = Transcription::of(base64_encode('audio-2'))->generate();
    expect($response->text)->toEqual('Fake transcription text.');
});

test('transcriptions can be faked with a single closure that is invoked for every generation', function () {
    $counter = 0;

    Transcription::fake(function (TranscriptionPrompt $prompt) use (&$counter) {
        $counter++;

        return "Transcription {$counter}";
    });

    $response = Transcription::of(base64_encode('audio-1'))->generate();
    expect($response->text)->toEqual('Transcription 1');

    $response = Transcription::of(base64_encode('audio-2'))->generate();
    expect($response->text)->toEqual('Transcription 2');
});

test('transcriptions can prevent stray generations', function () {
    Transcription::fake()->preventStrayTranscriptions();

    Transcription::of(base64_encode('audio'))->generate();
})->throws(RuntimeException::class);

test('fake closures can throw exceptions', function () {
    Transcription::fake(function () {
        throw new Exception('Something went wrong');
    });

    Transcription::of(base64_encode('audio'))->generate();
})->throws(Exception::class);

test('transcription language and diarize are recorded', function () {
    Transcription::fake();

    Transcription::of(base64_encode('audio'))->language('en')->diarize()->generate();

    Transcription::assertGenerated(function (TranscriptionPrompt $prompt) {
        return $prompt->language === 'en' && $prompt->isDiarized();
    });
});

test('fake transcriptions include segments', function () {
    Transcription::fake(['Hello world']);

    $response = Transcription::of(base64_encode('audio'))->generate();

    expect($response->segments)->toHaveCount(1)
        ->and($response->segments[0]->text)->toEqual('Hello world')
        ->and($response->segments[0]->speaker)->toEqual('Speaker 1');
});

test('queued transcriptions can be faked', function () {
    Transcription::fake();

    Transcription::fromPath('/path/to/audio.mp3')->queue();

    Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt) => $prompt->audio->path === '/path/to/audio.mp3');
    Transcription::assertNotQueued(fn (QueuedTranscriptionPrompt $prompt) => $prompt->audio->path === '/path/to/other.mp3');

    Transcription::assertQueued(function (QueuedTranscriptionPrompt $prompt) {
        return $prompt->audio->path === '/path/to/audio.mp3';
    });

    Transcription::assertNotQueued(function (QueuedTranscriptionPrompt $prompt) {
        return $prompt->audio->path === '/path/to/other.mp3';
    });
});

test('can assert no transcriptions were queued', function () {
    Transcription::fake();

    Transcription::assertNothingQueued();
});

test('generate accepts ai provider enum', function () {
    Transcription::fake();

    Transcription::of(base64_encode('audio'))->generate(provider: Lab::OpenAI);

    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt) => true);
});

test('queued transcription accepts ai provider enum', function () {
    Transcription::fake();

    Transcription::fromPath('/path/to/audio.mp3')->queue(provider: Lab::OpenAI);

    Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt) => $prompt->provider === Lab::OpenAI);
});

test('queued transcription language and diarize are recorded', function () {
    Transcription::fake();

    Transcription::fromPath('/path/to/audio.mp3')->language('es')->diarize()->queue();

    Transcription::assertQueued(function (QueuedTranscriptionPrompt $prompt) {
        return $prompt->language === 'es' && $prompt->isDiarized();
    });
});

test('transcription can have timeouts', function () {
    Transcription::fake();

    Transcription::of(base64_encode('audio'))->timeout(60)->generate();

    Transcription::assertGenerated(function (TranscriptionPrompt $prompt) {
        return $prompt->timeout === 60;
    });
});
