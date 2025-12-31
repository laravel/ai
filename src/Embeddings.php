<?php

namespace Laravel\Ai;

use Closure;
use Laravel\Ai\Gateway\FakeEmbeddingGateway;
use Laravel\Ai\PendingResponses\PendingEmbeddingsGeneration;

class Embeddings
{
    /**
     * Get embedding vectors representing the given inputs.
     *
     * @param  string[]  $inputs
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
            fn () => (mt_rand() / mt_getrandmax()) * 2 - 1,
            range(1, $dimensions)
        );

        // Normalize the vector (unit length)...
        $magnitude = sqrt(array_sum(array_map(fn ($v) => $v * $v, $values)));

        return array_map(fn ($v) => $v / $magnitude, $values);
    }
}
