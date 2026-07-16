<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Video;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Prompts\QueuedEmbeddingsPrompt;

test('embeddings reject empty input list', function (): void {
    Embeddings::fake();

    Embeddings::for([])->generate();
})->throws(InvalidArgumentException::class, 'At least one input is required to generate embeddings.');

test('embeddings reject associative input array', function (): void {
    Embeddings::fake();

    Embeddings::for(['first' => 'Hello world'])->generate();
})->throws(InvalidArgumentException::class, 'Inputs to embed must be a list, not an associative array.');

test('embeddings reject blank string inputs', function (): void {
    Embeddings::fake();

    Embeddings::for([''])->generate();
})->throws(InvalidArgumentException::class, 'The input at index 0 must be a non-blank string.');

test('embeddings reject whitespace-only string inputs', function (): void {
    Embeddings::fake();

    Embeddings::for([" \t\n"])->generate();
})->throws(InvalidArgumentException::class, 'The input at index 0 must be a non-blank string.');

test('embeddings reject non-string inputs', function (): void {
    Embeddings::fake();

    Embeddings::for([123])->generate();
})->throws(InvalidArgumentException::class, 'The input at index 0 must be a string or an image, audio, document, or video file.');

test('embeddings report the offending index for blank inputs', function (): void {
    Embeddings::fake();

    Embeddings::for(['valid', 'also valid', ''])->generate();
})->throws(InvalidArgumentException::class, 'The input at index 2 must be a non-blank string.');

describe('generating embeddings', function (): void {
    test('can fake embeddings', function (): void {
        Embeddings::fake();

        $response = Embeddings::for(['Hello world'])->generate();

        expect($response)->toHaveCount(1)
            ->and($response->first())->toHaveCount(1536);
    });

    test('can fake embeddings with custom dimensions', function (): void {
        Embeddings::fake();

        $response = Embeddings::for(['Hello world'])->dimensions(512)->generate();

        expect($response)->toHaveCount(1)
            ->and($response->first())->toHaveCount(512);
    });

    test('can fake embeddings with multiple inputs', function (): void {
        Embeddings::fake();

        $response = Embeddings::for(['Hello', 'World', 'Test'])->generate();

        expect($response)->toHaveCount(3);
    });

    test('can iterate over response', function (): void {
        Embeddings::fake([
            [
                array_fill(0, 3, 0.1),
                array_fill(0, 3, 0.2),
            ],
        ]);

        $response = Embeddings::for(['Hello', 'World'])->dimensions(3)->generate();

        $embeddings = [];

        foreach ($response as $embedding) {
            $embeddings[] = $embedding;
        }

        expect($embeddings)->toEqual([
            array_fill(0, 3, 0.1),
            array_fill(0, 3, 0.2),
        ]);
    });

    test('can fake embeddings with image input', function (): void {
        Embeddings::fake();

        $response = Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->generate();

        expect($response)->toHaveCount(1);
    });

    test('can fake embeddings with audio input', function (): void {
        Embeddings::fake();

        $response = Embeddings::for([
            Audio::fromBase64(base64_encode('audio-bytes'), 'audio/mpeg'),
        ])->generate();

        expect($response)->toHaveCount(1);
    });

    test('can fake embeddings with document input', function (): void {
        Embeddings::fake();

        $response = Embeddings::for([
            Document::fromBase64(base64_encode('%PDF-1.4 fake'), 'application/pdf'),
        ])->generate();

        expect($response)->toHaveCount(1);
    });

    test('can fake embeddings with video input', function (): void {
        Embeddings::fake();

        $response = Embeddings::for([
            Video::fromBase64(base64_encode('video-bytes'), 'video/mp4'),
        ])->generate();

        expect($response)->toHaveCount(1);
    });

    test('non-text embeddings inputs are rejected by text-only providers', function (): void {
        Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->generate(provider: 'openai');
    })->throws(InvalidArgumentException::class, 'Provider [openai] only supports text embeddings inputs.');

    test('can fake embeddings with custom response', function (): void {
        $customEmbedding = array_fill(0, 100, 0.5);

        Embeddings::fake([
            [$customEmbedding],
        ]);

        $response = Embeddings::for(['Hello world'])->dimensions(100)->generate();

        expect($response->first())->toEqual($customEmbedding);
    });

    test('can fake embeddings with closure', function (): void {
        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
            fn (): array => array_fill(0, $prompt->dimensions, 0.1),
            $prompt->inputs
        ));

        $response = Embeddings::for(['Hello', 'World'])->dimensions(256)->generate();

        expect($response)->toHaveCount(2)
            ->and($response->first())->toHaveCount(256);
    });

    test('embeddings timeout defaults to sdk fallback', function (): void {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->generate();

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->timeout === 30);
    });

    test('fake embeddings closure receives timeout', function (): void {
        Embeddings::fake(function (EmbeddingsPrompt $prompt): array {
            expect($prompt->timeout)->toBe(45);

            return array_map(
                fn (): array => array_fill(0, $prompt->dimensions, 0.1),
                $prompt->inputs
            );
        });

        Embeddings::for(['Hello world'])->timeout(45)->generate();
    });

    test('fake embeddings prompt carries provider options', function (): void {
        Embeddings::fake();

        Embeddings::for(['Hello'])
            ->withProviderOptions(['input_type' => 'search_query'])
            ->generate();

        Embeddings::assertGenerated(
            fn (EmbeddingsPrompt $prompt): bool => $prompt->providerOptions === ['input_type' => 'search_query'],
        );
    });

    test('fake queued embeddings prompt carries provider options', function (): void {
        Embeddings::fake();

        Embeddings::for(['Hello'])
            ->withProviderOptions(['input_type' => 'search_query'])
            ->queue();

        Embeddings::assertQueued(
            fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->providerOptions === ['input_type' => 'search_query'],
        );
    });

    test('fake embeddings are normalized', function (): void {
        $embedding = Embeddings::fakeEmbedding(100);

        // Check it has the right dimensions...
        expect($embedding)->toHaveCount(100);

        // Check it's normalized (magnitude ~= 1)...
        $magnitude = sqrt(array_sum(array_map(fn ($v): int|float => $v * $v, $embedding)));
        expect($magnitude)->toEqualWithDelta(1.0, 0.0001);
    });

    test('can prevent stray embeddings generations', function (): void {
        Embeddings::fake()->preventStrayEmbeddings();

        Embeddings::for(['Hello world'])->generate();
    })->throws(RuntimeException::class);
});

describe('assertions', function (): void {
    test('can assert embeddings generated', function (): void {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->generate();

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => in_array('Hello world', $prompt->inputs));
    });

    test('can assert embeddings not generated', function (): void {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->generate();

        Embeddings::assertNotGenerated(fn (EmbeddingsPrompt $prompt): bool => in_array('Goodbye', $prompt->inputs));
    });

    test('can assert nothing generated', function (): void {
        Embeddings::fake();

        Embeddings::assertNothingGenerated();
    });
});

describe('queued embeddings', function (): void {
    test('queued embeddings can be faked', function (): void {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->queue();

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->contains('Hello'));
        Embeddings::assertNotQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->contains('Goodbye'));

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => in_array('Hello world', $prompt->inputs));

        Embeddings::assertNotQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => in_array('Goodbye', $prompt->inputs));
    });

    test('contains ignores non-text inputs', function (): void {
        Embeddings::fake();

        Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->generate();

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => ! $prompt->contains('Hello'));
    });

    test('queued contains ignores non-text inputs', function (): void {
        Embeddings::fake();

        Embeddings::for([
            Video::fromBase64(base64_encode('video-bytes'), 'video/mp4'),
        ])->queue();

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt) => ! $prompt->contains('Hello'));
    });

    test('can assert no embeddings were queued', function (): void {
        Embeddings::fake();

        Embeddings::assertNothingQueued();
    });

    test('queued embeddings dimensions are recorded', function (): void {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->dimensions(256)->queue();

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->dimensions === 256 && $prompt->count() === 1);
    });

    test('queued embeddings timeout is recorded', function (): void {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->timeout(90)->queue();

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->timeout === 90 && $prompt->count() === 1);
    });

    test('cached embeddings with media inputs use content hashes', function () {
        config([
            'cache.default' => 'array',
            'ai.caching.embeddings.store' => 'array',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'ai-embedding-');

        file_put_contents($path, 'first-version');

        try {
            $calls = 0;

            Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls) {
                $calls++;

                return array_map(
                    fn () => array_fill(0, $prompt->dimensions, 0.1),
                    $prompt->inputs
                );
            });

            $request = fn () => Embeddings::for([
                Document::fromPath($path),
            ])->cache(60)->generate();

            $request();
            $request();

            file_put_contents($path, 'second-version');

            $request();

            expect($calls)->toBe(2);
        } finally {
            @unlink($path);
        }
    });

    test('cached remote embeddings do not fetch remote metadata', function () {
        config([
            'cache.default' => 'array',
            'ai.caching.embeddings.store' => 'array',
        ]);

        Http::preventStrayRequests();

        $calls = 0;

        Embeddings::fake(function () use (&$calls) {
            $calls++;

            return [array_fill(0, 100, 0.1)];
        });

        $request = fn () => Embeddings::for([
            Document::fromUrl('https://example.com/manual.pdf'),
        ])->cache(60)->generate();

        $request();
        $request();

        expect($calls)->toBe(1);
        Http::assertNothingSent();
    });

    test('cached embeddings reject unsupported input types with an invalid argument exception', function () {
        config([
            'cache.default' => 'array',
            'ai.caching.embeddings.store' => 'array',
        ]);

        Embeddings::fake();

        Embeddings::for([123])->cache(60)->generate();
    })->throws(InvalidArgumentException::class, 'The input at index 0 must be a string or an image, audio, document, or video file.');
});

describe('provider enum support', function (): void {
    test('generate accepts ai provider enum', function (): void {
        Embeddings::fake();

        Embeddings::for(['Enum test'])->generate(provider: Lab::OpenAI);

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => in_array('Enum test', $prompt->inputs));
    });

    test('queued embeddings accept ai provider enum', function (): void {
        Embeddings::fake();

        Embeddings::for(['Queued enum'])->queue(provider: Lab::Gemini);

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->contains('Queued enum')
            && $prompt->provider === Lab::Gemini);
    });
});
