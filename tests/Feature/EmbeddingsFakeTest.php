<?php

use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Prompts\QueuedEmbeddingsPrompt;

describe('generating embeddings', function () {
    test('can fake embeddings', function () {
        Embeddings::fake();

        $response = Embeddings::for(['Hello world'])->generate();

        expect($response)->toHaveCount(1)
            ->and($response->first())->toHaveCount(1536);
    });

    test('can fake embeddings with custom dimensions', function () {
        Embeddings::fake();

        $response = Embeddings::for(['Hello world'])->dimensions(512)->generate();

        expect($response)->toHaveCount(1)
            ->and($response->first())->toHaveCount(512);
    });

    test('can fake embeddings with multiple inputs', function () {
        Embeddings::fake();

        $response = Embeddings::for(['Hello', 'World', 'Test'])->generate();

        expect($response)->toHaveCount(3);
    });

    test('can fake embeddings with custom response', function () {
        $customEmbedding = array_fill(0, 100, 0.5);

        Embeddings::fake([
            [$customEmbedding],
        ]);

        $response = Embeddings::for(['Hello world'])->dimensions(100)->generate();

        expect($response->first())->toEqual($customEmbedding);
    });

    test('can fake embeddings with closure', function () {
        Embeddings::fake(function (EmbeddingsPrompt $prompt) {
            return array_map(
                fn () => array_fill(0, $prompt->dimensions, 0.1),
                $prompt->inputs
            );
        });

        $response = Embeddings::for(['Hello', 'World'])->dimensions(256)->generate();

        expect($response)->toHaveCount(2)
            ->and($response->first())->toHaveCount(256);
    });

    test('embeddings timeout defaults to sdk fallback', function () {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->generate();

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->timeout === 30);
    });

    test('fake embeddings closure receives timeout', function () {
        Embeddings::fake(function (EmbeddingsPrompt $prompt) {
            expect($prompt->timeout)->toBe(45);

            return array_map(
                fn () => array_fill(0, $prompt->dimensions, 0.1),
                $prompt->inputs
            );
        });

        Embeddings::for(['Hello world'])->timeout(45)->generate();
    });

    test('fake embeddings are normalized', function () {
        $embedding = Embeddings::fakeEmbedding(100);

        // Check it has the right dimensions...
        expect($embedding)->toHaveCount(100);

        // Check it's normalized (magnitude ~= 1)...
        $magnitude = sqrt(array_sum(array_map(fn ($v) => $v * $v, $embedding)));
        expect($magnitude)->toEqualWithDelta(1.0, 0.0001);
    });

    test('can prevent stray embeddings generations', function () {
        Embeddings::fake()->preventStrayEmbeddings();

        Embeddings::for(['Hello world'])->generate();
    })->throws(RuntimeException::class);
});

describe('assertions', function () {
    test('can assert embeddings generated', function () {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->generate();

        Embeddings::assertGenerated(function (EmbeddingsPrompt $prompt) {
            return in_array('Hello world', $prompt->inputs);
        });
    });

    test('can assert embeddings not generated', function () {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->generate();

        Embeddings::assertNotGenerated(function (EmbeddingsPrompt $prompt) {
            return in_array('Goodbye', $prompt->inputs);
        });
    });

    test('can assert nothing generated', function () {
        Embeddings::fake();

        Embeddings::assertNothingGenerated();
    });
});

describe('queued embeddings', function () {
    test('queued embeddings can be faked', function () {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->queue();

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt) => $prompt->contains('Hello'));
        Embeddings::assertNotQueued(fn (QueuedEmbeddingsPrompt $prompt) => $prompt->contains('Goodbye'));

        Embeddings::assertQueued(function (QueuedEmbeddingsPrompt $prompt) {
            return in_array('Hello world', $prompt->inputs);
        });

        Embeddings::assertNotQueued(function (QueuedEmbeddingsPrompt $prompt) {
            return in_array('Goodbye', $prompt->inputs);
        });
    });

    test('can assert no embeddings were queued', function () {
        Embeddings::fake();

        Embeddings::assertNothingQueued();
    });

    test('queued embeddings dimensions are recorded', function () {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->dimensions(256)->queue();

        Embeddings::assertQueued(function (QueuedEmbeddingsPrompt $prompt) {
            return $prompt->dimensions === 256 && $prompt->count() === 1;
        });
    });

    test('queued embeddings timeout is recorded', function () {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->timeout(90)->queue();

        Embeddings::assertQueued(function (QueuedEmbeddingsPrompt $prompt) {
            return $prompt->timeout === 90 && $prompt->count() === 1;
        });
    });
});

describe('provider enum support', function () {
    test('generate accepts ai provider enum', function () {
        Embeddings::fake();

        Embeddings::for(['Enum test'])->generate(provider: Lab::OpenAI);

        Embeddings::assertGenerated(function (EmbeddingsPrompt $prompt) {
            return in_array('Enum test', $prompt->inputs);
        });
    });

    test('queued embeddings accept ai provider enum', function () {
        Embeddings::fake();

        Embeddings::for(['Queued enum'])->queue(provider: Lab::Gemini);

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt) => $prompt->contains('Queued enum')
            && $prompt->provider === Lab::Gemini);
    });
});
