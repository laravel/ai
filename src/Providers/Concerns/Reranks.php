<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\Reranked;
use Laravel\Ai\Events\Reranking;
use Laravel\Ai\Events\RerankingFailed;
use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Responses\RerankingResponse;
use Throwable;

trait Reranks
{
    /**
     * Rerank the given documents based on their relevance to the query.
     *
     * @param  array<int, string>  $documents
     * @param  array<string, mixed>  $providerOptions
     */
    public function rerank(array $documents, string $query, ?int $limit = null, ?string $model = null, int $timeout = 30, array $providerOptions = []): RerankingResponse
    {
        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultRerankingModel();

        $prompt = new RerankingPrompt($documents, $query, $limit, $this, $model, $timeout, $providerOptions);

        if (Ai::rerankingIsFaked()) {
            Ai::recordReranking($prompt);
        }

        $this->events->dispatch(new Reranking(
            $invocationId, $this, $model, $prompt,
        ));

        try {
            $response = $this->rerankingGateway()->rerank(
                $this,
                $model,
                $documents,
                $query,
                $limit,
                $timeout,
                $providerOptions,
            );
        } catch (Throwable $e) {
            $this->events->dispatch(new RerankingFailed(
                $invocationId, $this, $model, $prompt, $e,
            ));

            throw $e;
        }

        $this->events->dispatch(new Reranked(
            $invocationId, $this, $model, $prompt, $response,
        ));

        return $response;
    }
}
