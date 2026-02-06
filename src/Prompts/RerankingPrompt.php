<?php

namespace Laravel\Ai\Prompts;

use Countable;
use Laravel\Ai\Contracts\Providers\RerankingProvider;

class RerankingPrompt implements Countable
{
    /**
     * Create a new reranking prompt instance.
     *
     * @param  array<int, string>  $documents
     */
    public function __construct(
        public readonly array $documents,
        public readonly string $query,
        public readonly ?int $limit,
        public readonly RerankingProvider $provider,
        public readonly string $model,
    ) {}

    /**
     * Determine if the query contains the given string.
     */
    public function contains(string $string): bool
    {
        return str_contains($this->query, $string);
    }

    /**
     * Determine if any of the documents contain the given string.
     */
    public function documentsContain(string $string): bool
    {
        return array_any($this->documents, fn ($document) => str_contains($document, $string));
    }

    /**
     * Get the number of documents in the prompt.
     */
    public function count(): int
    {
        return count($this->documents);
    }
}
