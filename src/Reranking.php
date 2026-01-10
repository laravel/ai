<?php

namespace Laravel\Ai;

use Closure;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Gateway\FakeRerankingGateway;
use Laravel\Ai\PendingResponses\PendingReranking;

class Reranking
{
    /**
     * Create a new pending reranking for the given documents.
     *
     * @param  Collection<int, string>|array<int, string>  $documents
     */
    public static function of(Collection|array $documents): PendingReranking
    {
        if ($documents instanceof Collection) {
            $documents = $documents->values()->all();
        }

        if (! array_is_list($documents)) {
            throw new InvalidArgumentException("Documents to rerank must be a list, not an associative array.");
        }

        return new PendingReranking($documents);
    }

    /**
     * Fake reranking operations.
     */
    public static function fake(Closure|array $responses = []): FakeRerankingGateway
    {
        return Ai::fakeReranking($responses);
    }

    /**
     * Assert that a reranking was performed matching a given truth test.
     */
    public static function assertReranked(Closure $callback): void
    {
        Ai::assertReranked($callback);
    }

    /**
     * Assert that a reranking was not performed matching a given truth test.
     */
    public static function assertNotReranked(Closure $callback): void
    {
        Ai::assertNotReranked($callback);
    }

    /**
     * Assert that no rerankings were performed.
     */
    public static function assertNothingReranked(): void
    {
        Ai::assertNothingReranked();
    }

    /**
     * Determine if reranking is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::rerankingIsFaked();
    }
}
