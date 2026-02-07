<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\UniqueIdentifierGenerator;
use Laravel\Ai\Events\Reranked;
use Laravel\Ai\Events\Reranking;
use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Responses\RerankingResponse;

trait Reranks
{
    /**
     * Rerank the given documents based on their relevance to the query.
     *
     * @param  array<int, string>  $documents
     */
    public function rerank(array $documents, string $query, ?int $limit = null, ?string $model = null): RerankingResponse
    {
        $invocationId = resolve(UniqueIdentifierGenerator::class)->generate();

        $model ??= $this->defaultRerankingModel();

        $prompt = new RerankingPrompt($documents, $query, $limit, $this, $model);

        if (Ai::rerankingIsFaked()) {
            Ai::recordReranking($prompt);
        }

        $this->events->dispatch(new Reranking(
            $invocationId, $this, $model, $prompt,
        ));

        return tap($this->rerankingGateway()->rerank(
            $this,
            $model,
            $documents,
            $query,
            $limit
        ), fn (RerankingResponse $response) => $this->events->dispatch(new Reranked(
            $invocationId, $this, $model, $prompt, $response,
        )));
    }
}
