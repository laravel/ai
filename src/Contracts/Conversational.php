<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Messages\Message;

interface Conversational
{
    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return Message[]
     */
    public function messages(): iterable;
}
