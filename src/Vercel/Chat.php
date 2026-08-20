<?php

namespace Laravel\Ai\Vercel;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\ChatInput;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;

class Chat implements ChatInput
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function __construct(protected array $messages) {}

    /**
     * Get the newest user message, if the input contains one.
     */
    public function message(): ?UserMessage
    {
        $message = $this->latestMessageOfRole('user');

        return $message === null ? null : Vercel::messageFrom($message);
    }

    /**
     * Get the tool approval decisions from the newest assistant message, if the input contains any.
     */
    public function decisions(): ?Decisions
    {
        $message = $this->latestAssistantMessage();

        $responses = $message === null ? [] : Vercel::approvalResponsesFrom($message);

        return $responses === [] ? null : Decisions::from($responses);
    }

    /**
     * Get every turn before the one this input resolves to.
     *
     * @return list<Message>
     */
    public function history(): array
    {
        // On a resume turn the trailing assistant message carries the pending tool calls and must replay...
        $messages = $this->latestMessageOfRole('user') !== null
            ? array_slice($this->messages, 0, -1)
            : $this->messages;

        return Vercel::messagesFrom($messages);
    }

    /**
     * Get the newest message when it matches the given role.
     *
     * @return array<string, mixed>|null
     */
    protected function latestMessageOfRole(string $role): ?array
    {
        $message = $this->messages === [] ? null : $this->messages[array_key_last($this->messages)];

        return ($message['role'] ?? null) === $role ? $message : null;
    }

    /**
     * Get the newest assistant message, looking past a user message submitted in the same turn.
     *
     * @return array<string, mixed>|null
     */
    protected function latestAssistantMessage(): ?array
    {
        $messages = $this->latestMessageOfRole('user') !== null
            ? array_slice($this->messages, 0, -1)
            : $this->messages;

        $message = $messages === [] ? null : $messages[array_key_last($messages)];

        return ($message['role'] ?? null) === 'assistant' ? $message : null;
    }
}
