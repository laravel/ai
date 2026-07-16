<?php

use Laravel\Ai\Image;
use Laravel\Ai\Enums\Lab;
use Illuminate\Support\Collection;
use Laravel\Ai\Jobs\GenerateImage;
use Laravel\Ai\Prompts\ImagePrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Prompts\QueuedImagePrompt;
use Laravel\Ai\Responses\Data\GeneratedImage;

test('image rejects empty prompt', function (): void {
    Image::fake();

    Image::of('')->generate();
})->throws(InvalidArgumentException::class, 'A prompt is required to generate an image.');

test('image rejects whitespace-only prompt', function (): void {
    Image::fake();

    Image::of('   ')->generate();
})->throws(InvalidArgumentException::class, 'A prompt is required to generate an image.');

test('images can be faked', function (): void {
    Image::fake([
        base64_encode('first-image'),
        fn (ImagePrompt $prompt): string => base64_encode('second-image-'.$prompt->prompt),
        new ImageResponse(
            new Collection([new GeneratedImage(base64_encode('third-image'))]),
            new Usage,
            new Meta,
        ),
    ]);

    $response = Image::of('First prompt')->generate();
    expect($response->firstImage()->image)->toEqual(base64_encode('first-image'));

    $response = Image::of('Second prompt')->generate();
    expect($response->firstImage()->image)->toEqual(base64_encode('second-image-Second prompt'));

    $response = Image::of('Third prompt')->generate();
    expect($response->firstImage()->image)->toEqual(base64_encode('third-image'));

    // Assertion tests...
    Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->prompt === 'First prompt');
    Image::assertNotGenerated(fn (ImagePrompt $prompt): bool => $prompt->prompt === 'Missing prompt');

    Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->prompt === 'First prompt');
});

test('can assert no images were generated', function (): void {
    Image::fake();

    Image::assertNothingGenerated();
});

test('images can be faked with no predefined responses', function (): void {
    Image::fake();

    $response = Image::of('First prompt')->generate();
    expect($response->firstImage()->image)->toEqual(base64_encode('fake-image-content'));

    $response = Image::of('Second prompt')->generate();
    expect($response->firstImage()->image)->toEqual(base64_encode('fake-image-content'));
});

test('images can be faked with a single closure that is invoked for every generation', function (): void {
    Image::fake(fn (ImagePrompt $prompt): string => base64_encode('image-for-'.$prompt->prompt));

    $response = Image::of('First prompt')->generate();
    expect($response->firstImage()->image)->toEqual(base64_encode('image-for-First prompt'));

    $response = Image::of('Second prompt')->generate();
    expect($response->firstImage()->image)->toEqual(base64_encode('image-for-Second prompt'));
});

test('images can prevent stray generations', function (): void {
    Image::fake()->preventStrayImages();

    Image::of('First prompt')->generate();
})->throws(RuntimeException::class);

test('fake closures can throw exceptions', function (): void {
    Image::fake(function (): void {
        throw new Exception('Something went wrong');
    });

    Image::of('Test prompt')->generate();
})->throws(Exception::class);

test('image size and quality are recorded', function (): void {
    Image::fake();

    Image::of('A sunset')->square()->quality('high')->generate();

    Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->prompt === 'A sunset'
        && $prompt->size === '1:1'
        && $prompt->quality === 'high');
});

test('image portrait aspect ratio is recorded', function (): void {
    Image::fake();

    Image::of('A sunset')->portrait()->generate();

    Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->prompt === 'A sunset'
        && $prompt->size === '2:3');
});

test('image custom size is recorded', function (): void {
    Image::fake();

    Image::of('A sunset')->size('16:9')->generate();

    Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->prompt === 'A sunset'
        && $prompt->size === '16:9');
});

test('queued images can be faked', function (): void {
    Image::fake();

    Image::of('First prompt')->queue();

    Image::assertQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->prompt === 'First prompt');
    Image::assertNotQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->contains('Second prompt'));

    Image::assertQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->prompt === 'First prompt');

    Image::assertNotQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->prompt === 'Second prompt');
});

test('queued images can be faked and then callback is executed', function (): void {
    Image::fake([base64_encode('image')]);

    $GLOBALS['imageResponse'] = null;

    Image::of('First prompt')->queue()->then(function ($response): void {
        $GLOBALS['imageResponse'] = $response;
    });

    Image::assertQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->prompt === 'First prompt');

    expect($GLOBALS['imageResponse'])->toBeInstanceOf(ImageResponse::class);
    expect($GLOBALS['imageResponse']->firstImage()->image)->toEqual(base64_encode('image'));
});

test('queued images can be faked and then callback is executed if queue is faked', function (): void {
    Image::fake([base64_encode('image')]);
    Queue::fake();

    $GLOBALS['imageResponse'] = null;

    Image::of('First prompt')->queue()->then(function ($response): void {
        $GLOBALS['imageResponse'] = $response;
    });

    Image::assertQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->prompt === 'First prompt');

    expect($GLOBALS['imageResponse'])->toBeNull();

    Queue::assertPushed(GenerateImage::class);
});

test('can assert no images were queued', function (): void {
    Image::fake();

    Image::assertNothingQueued();
});

test('generate accepts ai provider enum', function (): void {
    Image::fake();

    Image::of('Enum image')->generate(provider: Lab::Gemini);

    Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->prompt === 'Enum image');
});

test('queued image accepts ai provider enum', function (): void {
    Image::fake();

    Image::of('Queued enum image')->queue(provider: Lab::OpenAI);

    Image::assertQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->prompt === 'Queued enum image'
        && $prompt->provider === Lab::OpenAI);
});

test('queued image size and quality are recorded', function (): void {
    Image::fake();

    Image::of('A sunset')->landscape()->quality('low')->queue();

    Image::assertQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->prompt === 'A sunset'
        && $prompt->size === '3:2'
        && $prompt->quality === 'low');
});
