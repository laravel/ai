<?php

use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
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
})->throws(InvalidArgumentException::class, 'The input at index 0 must be a non-blank string.');

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

    test('can iterate over response', function () {
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
