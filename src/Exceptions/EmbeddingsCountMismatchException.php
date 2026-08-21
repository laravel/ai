<?php

namespace Laravel\Ai\Exceptions;

class EmbeddingsCountMismatchException extends AiException
{
    public function __construct(public readonly int $expected, public readonly int $actual)
    {
        parent::__construct(sprintf('Provider returned %d embeddings for %d inputs.', $actual, $expected));
    }
}
