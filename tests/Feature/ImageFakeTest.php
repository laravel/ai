<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;
use Laravel\Ai\Prompts\QueuedImagePrompt;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;

test('image rejects empty prompt', function () {
    Image::fake();

    Image::of('')->generate();
})->throws(InvalidArgumentException::class, 'A prompt is required to generate an image.');

test('image rejects whitespace-only prompt', function () {
    Image::fake();

    Image::of('   ')->generate();
})->throws(InvalidArgumentException::class, 'A prompt is required to generate an image.');

test('images can be faked', function () {
    Image::fake([
        base64_encode('first-image'),
        fn (ImagePrompt $prompt) => base64_encode('second-image-'.$prompt->prompt),
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
    Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->prompt === 'First prompt');
    Image::assertNotGenerated(fn (ImagePrompt $prompt) => $prompt->prompt === 'Missing prompt');

    Image::assertGenerated(function (ImagePrompt $prompt) {
        return $prompt->prompt === 'First prompt';
    });
});

test('can assert no images were generated', function () {
    Image::fake();

    Image::assertNothingGenerated();
});

test('images can be faked with no predefined responses', function () {
    Image::fake();

    $response = Image::of('First prompt')->generate();
    expect($response->firstImage()->image)->toEqual(base64_encode('fake-image-content'));

    $response = Image::of('Second prompt')->generate();
    expect($response->firstImage()->image)->toEqual(base64_encode('fake-image-content'));
});

test('images can be faked with a single closure that is invoked for every generation', function () {
    Image::fake(function (ImagePrompt $prompt) {
        return base64_encode('image-for-'.$prompt->prompt);
    });

    $response = Image::of('First prompt')->generate();
    expect($response->firstImage()->image)->toEqual(base64_encode('image-for-First prompt'));

    $response = Image::of('Second prompt')->generate();
    expect($response->firstImage()->image)->toEqual(base64_encode('image-for-Second prompt'));
});

test('images can prevent stray generations', function () {
    Image::fake()->preventStrayImages();

    Image::of('First prompt')->generate();
})->throws(RuntimeException::class);

test('fake closures can throw exceptions', function () {
    Image::fake(function () {
        throw new Exception('Something went wrong');
    });

    Image::of('Test prompt')->generate();
})->throws(Exception::class);

test('image size and quality are recorded', function () {
    Image::fake();

    Image::of('A sunset')->square()->quality('high')->generate();

    Image::assertGenerated(function (ImagePrompt $prompt) {
        return $prompt->prompt === 'A sunset'
            && $prompt->size === '1:1'
            && $prompt->quality === 'high';
    });
});

test('queued images can be faked', function () {
    Image::fake();

    Image::of('First prompt')->queue();

    Image::assertQueued(fn (QueuedImagePrompt $prompt) => $prompt->prompt === 'First prompt');
    Image::assertNotQueued(fn (QueuedImagePrompt $prompt) => $prompt->contains('Second prompt'));

    Image::assertQueued(function (QueuedImagePrompt $prompt) {
        return $prompt->prompt === 'First prompt';
    });

    Image::assertNotQueued(function (QueuedImagePrompt $prompt) {
        return $prompt->prompt === 'Second prompt';
    });
});

test('can assert no images were queued', function () {
    Image::fake();

    Image::assertNothingQueued();
});

test('generate accepts ai provider enum', function () {
    Image::fake();

    Image::of('Enum image')->generate(provider: Lab::Gemini);

    Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->prompt === 'Enum image');
});

test('queued image accepts ai provider enum', function () {
    Image::fake();

    Image::of('Queued enum image')->queue(provider: Lab::OpenAI);

    Image::assertQueued(fn (QueuedImagePrompt $prompt) => $prompt->prompt === 'Queued enum image'
        && $prompt->provider === Lab::OpenAI);
});

test('queued image size and quality are recorded', function () {
    Image::fake();

    Image::of('A sunset')->landscape()->quality('low')->queue();

    Image::assertQueued(function (QueuedImagePrompt $prompt) {
        return $prompt->prompt === 'A sunset'
            && $prompt->size === '3:2'
            && $prompt->quality === 'low';
    });
});
