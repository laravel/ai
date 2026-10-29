<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Provider('groq')]
#[Model('llama-3.1-8b-instant')]
class CustomTitleAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Title the conversation.';
    }
}
