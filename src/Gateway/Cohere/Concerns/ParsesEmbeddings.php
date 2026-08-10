<?php

namespace Laravel\Ai\Gateway\Cohere\Concerns;

use Laravel\Ai\Exceptions\AiException;

trait ParsesEmbeddings
{
    /**
     * Normalize the embeddings of a Cohere response into a list of vectors.
     *
     * @return array<int, array<int, float>>
     *
     * @throws AiException
     */
    protected function parseCohereEmbeddings(mixed $embeddings): array
    {
        if (! is_array($embeddings)) {
            return [];
        }

        // Cohere returns a bare list of vectors, or an object keyed by embedding type, depending on the requested embedding types.
        if (! array_is_list($embeddings)) {
            return $embeddings['float'] ?? throw new AiException(sprintf(
                'Cohere returned [%s] embeddings, but only float embeddings are supported.',
                implode(', ', array_keys($embeddings)),
            ));
        }

        return $embeddings;
    }
}
