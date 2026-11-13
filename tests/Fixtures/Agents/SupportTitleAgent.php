<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Agents\ConversationTitleAgent;
use Laravel\Ai\Attributes\Model;

#[Model('llama-3.1-8b-instant')]
class SupportTitleAgent extends ConversationTitleAgent
{
    public function instructions(): string
    {
        return 'Title this support thread in 3-6 words, naming the product area first.';
    }
}
