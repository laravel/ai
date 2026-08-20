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
        $message = $this->latestMessageOfRole('assistant');

        $responses = collect($message['parts'] ?? [])
            ->filter(fn (array $part) => str_starts_with($part['type'] ?? '', 'tool-')
                && isset($part['toolCallId'])
                && is_bool($part['approval']['approved'] ?? null))
            ->mapWithKeys(fn (array $part) => [$part['toolCallId'] => $part['approval']['approved']]);

        return $responses->isEmpty() ? null : Decisions::from($responses->all());
    }

    /**
     * Get every turn before the one this input resolves to.
     *
     * @return list<Message>
     */
    public function history(): array
    {
        return Vercel::messagesFrom(array_slice($this->messages, 0, -1));
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
}
