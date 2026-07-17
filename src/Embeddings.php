<?php

namespace Laravel\Ai;

use Closure;
use InvalidArgumentException;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Video;
use Laravel\Ai\Gateway\FakeEmbeddingGateway;
use Laravel\Ai\PendingResponses\PendingEmbeddingsGeneration;

class Embeddings
{
    /**
     * Get embedding vectors representing the given inputs.
     *
     * @param  array<int, string|Audio|Document|Image|Video>  $inputs
     *
     * @throws InvalidArgumentException if the given inputs are not a list, are empty, or contain an unsupported input type.
     */
    public static function for(array $inputs): PendingEmbeddingsGeneration
    {
        return new PendingEmbeddingsGeneration($inputs);
    }

    /**
     * Fake embeddings generation.
     */
    public static function fake(Closure|array $responses = []): FakeEmbeddingGateway
    {
        return Ai::fakeEmbeddings($responses);
    }

    /**
     * Assert that embeddings were generated matching a given truth test.
     */
    public static function assertGenerated(Closure $callback): void
    {
        Ai::assertEmbeddingsGenerated($callback);
    }

    /**
     * Assert that embeddings were not generated matching a given truth test.
     */
    public static function assertNotGenerated(Closure $callback): void
    {
        Ai::assertEmbeddingsNotGenerated($callback);
    }

    /**
     * Assert that no embeddings were generated.
     */
    public static function assertNothingGenerated(): void
    {
        Ai::assertNoEmbeddingsGenerated();
    }

    /**
     * Assert that a queued embeddings generation was recorded matching a given truth test.
     */
    public static function assertQueued(Closure $callback): void
    {
        Ai::assertEmbeddingsQueued($callback);
    }

    /**
     * Assert that a queued embeddings generation was not recorded matching a given truth test.
     */
    public static function assertNotQueued(Closure $callback): void
    {
        Ai::assertEmbeddingsNotQueued($callback);
    }

    /**
     * Assert that no queued embeddings generations were recorded.
     */
    public static function assertNothingQueued(): void
    {
        Ai::assertNoEmbeddingsQueued();
    }

    /**
     * Determine if embeddings generation is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::embeddingsAreFaked();
    }

    /**
     * Generate a fake embedding vector of the given dimensions.
     *
     * @return array<float>
     */
    public static function fakeEmbedding(int $dimensions): array
    {
        // Generate random values...
        $values = array_map(
            fn (): float|int => (mt_rand() / mt_getrandmax()) * 2 - 1,
            range(1, $dimensions)
        );

        // Normalize the vector (unit length)...
        $magnitude = sqrt(array_sum(array_map(fn ($v): int|float => $v * $v, $values)));

        return array_map(fn ($v): float => $v / $magnitude, $values);
    }
}
