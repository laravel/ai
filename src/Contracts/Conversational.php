<?php

namespace Laravel\Ai\Contracts;

interface Conversational
{
    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return \Laravel\Ai\Messages\Message[]
     */
    public function messages(): iterable;
}
