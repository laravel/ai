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
        // Embed v4 keys the vectors by embedding type, while earlier models return a bare list.
        if (is_array($embeddings) && ! array_is_list($embeddings)) {
            $embeddings = $embeddings['float'] ?? throw new AiException(sprintf(
                'Cohere returned [%s] embeddings, but only float embeddings are supported.',
                implode(', ', array_keys($embeddings)),
            ));
        }

        return is_array($embeddings)
            ? array_values(array_filter($embeddings, is_array(...)))
            : [];
    }
}
