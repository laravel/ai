<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Middleware\TrimConversations;
use Laravel\Ai\Promptable;

class TrimmingAgent implements Agent, Conversational, HasMiddleware
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function messages(): iterable
    {
        return [
            new UserMessage('Message one'),
            new AssistantMessage('Reply one'),
            new UserMessage('Message two'),
            new AssistantMessage('Reply two'),
        ];
    }

    public function middleware(): array
    {
        return [new TrimConversations(keep: 2)];
    }
}
