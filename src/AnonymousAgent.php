<?php

namespace Laravel\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;

class AnonymousAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public function __construct(public string $instructions, public iterable $messages, public iterable $tools) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function messages(): iterable
    {
        return $this->messages;
    }

    public function tools(): iterable
    {
        return $this->tools;
    }
}
