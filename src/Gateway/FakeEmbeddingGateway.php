<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;
use RuntimeException;

class FakeEmbeddingGateway implements EmbeddingGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayGenerations = false;

    public function __construct(
        protected Closure|array $responses = [],
    ) {}

    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param  string[]  $inputs
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions
    ): EmbeddingsResponse {
        $prompt = new EmbeddingsPrompt($inputs, $dimensions, $provider, $model);

        return $this->nextResponse($provider, $model, $prompt);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(
        EmbeddingProvider $provider,
        string $model,
        EmbeddingsPrompt $prompt
    ): EmbeddingsResponse {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $prompt);

        return tap($this->marshalResponse(
            $response, $provider, $model, $prompt
        ), fn () => $this->currentResponseIndex++);
    }

    /**
     * Marshal the given response into a full response instance.
     */
    protected function marshalResponse(
        mixed $response,
        EmbeddingProvider $provider,
        string $model,
        EmbeddingsPrompt $prompt
    ): EmbeddingsResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayGenerations) {
                throw new RuntimeException('Attempted embedding generation without a fake response.');
            }

            $response = $this->generateFakeEmbeddings(
                count($prompt->inputs),
                $prompt->dimensions
            );
        }

        if (is_array($response) && isset($response[0]) && is_array($response[0])) {
            return new EmbeddingsResponse(
                $response,
                0,
                new Meta($provider->name(), $model),
            );
        }

        return $response;
    }

    /**
     * Generate fake embedding vectors.
     *
     * @return array<int, array<float>>
     */
    protected function generateFakeEmbeddings(int $count, int $dimensions): array
    {
        return array_map(
            fn () => Embeddings::fakeEmbedding($dimensions),
            range(1, $count)
        );
    }

    /**
     * Indicate that an exception should be thrown if any embeddings generation is not faked.
     */
    public function preventStrayEmbeddings(bool $prevent = true): self
    {
        $this->preventStrayGenerations = $prevent;

        return $this;
    }
}
