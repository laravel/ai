<?php

namespace Laravel\Ai\Vercel;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Protocols\VercelDataProtocol;

class Chat implements AgentInput
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
        $message = static::latestOfRole($this->messages, 'user');

        return $message === null ? null : Vercel::fromUiMessage($message);
    }

    /**
     * Get the tool approval decisions from the newest assistant message, if the input contains any.
     */
    public function decisions(): ?Decisions
    {
        $message = static::latestOfRole($this->precedingMessages(), 'assistant');

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
        return Vercel::fromUiMessages($this->precedingMessages());
    }

    /**
     * Get the stream protocol for the chat.
     */
    public function protocol(): VercelDataProtocol
    {
        return new VercelDataProtocol;
    }

    /**
     * Get the raw messages preceding the turn this input resolves to.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function precedingMessages(): array
    {
        return static::latestOfRole($this->messages, 'user') === null
            ? $this->messages
            : array_slice($this->messages, 0, -1);
    }

    /**
     * Get the newest of the given messages when it matches the given role.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>|null
     */
    protected static function latestOfRole(array $messages, string $role): ?array
    {
        $message = $messages === [] ? null : $messages[array_key_last($messages)];

        return ($message['role'] ?? null) === $role ? $message : null;
    }
}
