<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Jobs\GenerateTranscription;
use Laravel\Ai\Prompts\QueuedTranscriptionPrompt;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;

test('transcription rejects empty audio string', function (): void {
    Transcription::fake();

    Transcription::of('')->generate();
})->throws(InvalidArgumentException::class, 'Base64 audio content cannot be empty.');

test('transcription rejects empty base64 audio', function (): void {
    Transcription::fake();

    Transcription::fromBase64('')->generate();
})->throws(InvalidArgumentException::class, 'Base64 audio content cannot be empty.');

test('transcriptions can be faked', function (): void {
    Transcription::fake([
        'First transcription',
        fn (TranscriptionPrompt $prompt): string => 'Second transcription',
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
    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): true => true);
    Transcription::assertNotGenerated(fn (TranscriptionPrompt $prompt): bool => $prompt->language === 'fr');
});

test('can assert no transcriptions were generated', function (): void {
    Transcription::fake();

    Transcription::assertNothingGenerated();
});

test('transcriptions can be faked with no predefined responses', function (): void {
    Transcription::fake();

    $response = Transcription::of(base64_encode('audio-1'))->generate();
    expect($response->text)->toEqual('Fake transcription text.');

    $response = Transcription::of(base64_encode('audio-2'))->generate();
    expect($response->text)->toEqual('Fake transcription text.');
});

test('transcriptions can be faked with a single closure that is invoked for every generation', function (): void {
    $counter = 0;

    Transcription::fake(function (TranscriptionPrompt $prompt) use (&$counter): string {
        $counter++;

        return "Transcription {$counter}";
    });

    $response = Transcription::of(base64_encode('audio-1'))->generate();
    expect($response->text)->toEqual('Transcription 1');

    $response = Transcription::of(base64_encode('audio-2'))->generate();
    expect($response->text)->toEqual('Transcription 2');
});

test('transcriptions can prevent stray generations', function (): void {
    Transcription::fake()->preventStrayTranscriptions();

    Transcription::of(base64_encode('audio'))->generate();
})->throws(RuntimeException::class);

test('fake closures can throw exceptions', function (): void {
    Transcription::fake(function (): void {
        throw new Exception('Something went wrong');
    });

    Transcription::of(base64_encode('audio'))->generate();
})->throws(Exception::class);

test('transcription language and diarize are recorded', function (): void {
    Transcription::fake();

    Transcription::of(base64_encode('audio'))->language('en')->diarize()->generate();

    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => $prompt->language === 'en' && $prompt->isDiarized());
});

test('transcription provider options are recorded', function (): void {
    Transcription::fake();

    Transcription::of(base64_encode('audio'))
        ->withProviderOptions(['prompt' => 'Laravel Forge and Vapor'])
        ->generate();

    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => ($prompt->providerOptions['prompt'] ?? null) === 'Laravel Forge and Vapor');
});

test('fake transcriptions include segments', function (): void {
    Transcription::fake(['Hello world']);

    $response = Transcription::of(base64_encode('audio'))->generate();

    expect($response->segments)->toHaveCount(1)
        ->and($response->segments[0]->text)->toEqual('Hello world')
        ->and($response->segments[0]->speaker)->toEqual('Speaker 1');
});

test('fake transcriptions include a duration', function (): void {
    Transcription::fake(['Hello world']);

    $response = Transcription::of(base64_encode('audio'))->generate();

    expect($response->usage->durationSeconds)->toBe(1.0);
});

test('queued transcriptions can be faked', function (): void {
    Transcription::fake();

    Transcription::fromPath('/path/to/audio.mp3')->queue();

    Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->audio->path === '/path/to/audio.mp3');
    Transcription::assertNotQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->audio->path === '/path/to/other.mp3');

    Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->audio->path === '/path/to/audio.mp3');

    Transcription::assertNotQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->audio->path === '/path/to/other.mp3');
});

test('queued transcriptions can be faked and then callback is executed', function (): void {
    Transcription::fake(['Some transcription text']);

    $GLOBALS['transcriptionResponse'] = null;

    Transcription::fromPath('/path/to/audio.mp3')->queue()->then(function ($response): void {
        $GLOBALS['transcriptionResponse'] = $response;
    });

    Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->audio->path === '/path/to/audio.mp3');

    expect($GLOBALS['transcriptionResponse'])->toBeInstanceOf(TranscriptionResponse::class);
    expect($GLOBALS['transcriptionResponse']->text)->toEqual('Some transcription text');
});

test('queued transcriptions can be faked and then callback is not executed if queue is faked', function (): void {
    Transcription::fake(['Some transcription text']);
    Queue::fake();

    $GLOBALS['transcriptionResponse'] = null;

    Transcription::fromPath('/path/to/audio.mp3')->queue()->then(function ($response): void {
        $GLOBALS['transcriptionResponse'] = $response;
    });

    Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->audio->path === '/path/to/audio.mp3');

    expect($GLOBALS['transcriptionResponse'])->toBeNull();

    Queue::assertPushed(GenerateTranscription::class);
});

test('can assert no transcriptions were queued', function (): void {
    Transcription::fake();

    Transcription::assertNothingQueued();
});

test('generate accepts ai provider enum', function (): void {
    Transcription::fake();

    Transcription::of(base64_encode('audio'))->generate(provider: Lab::OpenAI);

    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): true => true);
});

test('queued transcription accepts ai provider enum', function (): void {
    Transcription::fake();

    Transcription::fromPath('/path/to/audio.mp3')->queue(provider: Lab::ElevenLabs);

    Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->provider === Lab::ElevenLabs);
});

test('queued transcription language and diarize are recorded', function (): void {
    Transcription::fake();

    Transcription::fromPath('/path/to/audio.mp3')->language('es')->diarize()->queue();

    Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->language === 'es' && $prompt->isDiarized());
});

test('queued transcription provider options are recorded', function (): void {
    Transcription::fake();

    Transcription::fromPath('/path/to/audio.mp3')
        ->withProviderOptions(['prompt' => 'Laravel Forge and Vapor'])
        ->queue();

    Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt): bool => ($prompt->providerOptions['prompt'] ?? null) === 'Laravel Forge and Vapor');
});

test('transcription can have timeouts', function (): void {
    Transcription::fake();

    Transcription::of(base64_encode('audio'))->timeout(60)->generate();

    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => $prompt->timeout === 60);
});
