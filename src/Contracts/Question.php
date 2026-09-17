<?php

namespace Laravel\Ai\Contracts;

interface Question
{
    /**
     * Get the question as a provider-neutral array.
     */
    public function toArray(): array;
}
