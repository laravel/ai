<?php

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Audio;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Jobs\GenerateAudio;
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

test('audio throws once its scripted responses are exhausted', function (): void {
    Audio::fake([base64_encode('only-audio')]);

    Audio::of('First text')->generate();

    Audio::of('Second text')->generate();
})->throws(RuntimeException::class, 'Fake audio responses exhausted: [1] response(s) were supplied but call [2] was made.');

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

test('audio is stored under a random name derived from its mime type', function (): void {
    Storage::fake('audio');

    Audio::fake([
        new AudioResponse(base64_encode('wav-bytes'), new Meta, 'audio/wav'),
        new AudioResponse(base64_encode('alias-bytes'), new Meta, 'audio/x-wav'),
        new AudioResponse(base64_encode('mp3-bytes'), new Meta),
    ]);

    $wav = Audio::of('First text')->generate()->store('generated', 'audio');
    $alias = Audio::of('Second text')->generate()->store('generated', 'audio');
    $default = Audio::of('Third text')->generate()->store('generated', 'audio');

    expect($wav)->toStartWith('generated/')
        ->and($wav)->toEndWith('.wav')
        ->and($alias)->toEndWith('.wav')
        ->and($default)->toEndWith('.mp3')
        ->and(Storage::disk('audio')->get($wav))->toBe('wav-bytes')
        ->and(Storage::disk('audio')->get($default))->toBe('mp3-bytes');
});

test('audio can be stored under an explicit path and name', function (): void {
    Storage::fake('audio');

    Audio::fake([base64_encode('raw-bytes')]);

    $response = Audio::of('Hello world')->generate();

    expect($response->storeAs('generated', 'hello.mp3', 'audio'))->toBe('generated/hello.mp3')
        ->and($response->storeAs('hello.mp3', null, 'audio'))->toBe('hello.mp3')
        ->and(Storage::disk('audio')->get('generated/hello.mp3'))->toBe('raw-bytes')
        ->and(Storage::disk('audio')->get('hello.mp3'))->toBe('raw-bytes');
});

test('storing audio publicly passes public visibility to the disk', function (): void {
    $writes = [];

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andReturnUsing(function (string $path, string $contents, array $options) use (&$writes): bool {
        $writes[] = ['path' => $path, 'options' => $options];

        return true;
    });

    $factory = Mockery::mock(FilesystemFactory::class);
    $factory->shouldReceive('disk')->with('audio')->andReturn($disk);

    app()->instance(FilesystemFactory::class, $factory);

    Audio::fake([base64_encode('raw-bytes')]);

    $response = Audio::of('Hello world')->generate();

    $response->store('generated', 'audio');
    $response->storePublicly('generated', 'audio');
    $response->storePubliclyAs('hello.mp3', null, 'audio');

    expect($writes[0]['options'])->toBe([])
        ->and($writes[1]['options'])->toBe(['visibility' => 'public'])
        ->and($writes[2]['options'])->toBe(['visibility' => 'public'])
        ->and($writes[2]['path'])->toBe('hello.mp3');
});

test('queued audio can be faked', function (): void {
    Audio::fake();

    Audio::of('First text')->queue();

    Audio::assertQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'First text');
    Audio::assertNotQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->contains('Second text'));

    Audio::assertQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'First text');

    Audio::assertNotQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'Second text');
});

test('queued audio can be faked and then callback is executed', function (): void {
    Audio::fake([base64_encode('audio')]);

    $GLOBALS['audioResponse'] = null;

    Audio::of('First text')->queue()->then(function ($response): void {
        $GLOBALS['audioResponse'] = $response;
    });

    Audio::assertQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'First text');

    expect($GLOBALS['audioResponse'])->toBeInstanceOf(AudioResponse::class);
    expect($GLOBALS['audioResponse']->audio)->toEqual(base64_encode('audio'));
});

test('queued audio can be faked and then callback is not executed if queue is faked', function (): void {
    Audio::fake([base64_encode('audio')]);
    Queue::fake();

    $GLOBALS['audioResponse'] = null;

    Audio::of('First text')->queue()->then(function ($response): void {
        $GLOBALS['audioResponse'] = $response;
    });

    Audio::assertQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'First text');

    expect($GLOBALS['audioResponse'])->toBeNull();

    Queue::assertPushed(GenerateAudio::class);
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
