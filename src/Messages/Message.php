<?php

namespace Laravel\Ai\Messages;

use InvalidArgumentException;

class Message
{
    /**
     * The message role.
     */
    public MessageRole $role;

    /**
     * The message content.
     */
    public ?string $content;

    /**
     * Create a new text conversation message instance.
     */
    public function __construct(MessageRole|string $role, ?string $content = '')
    {
        $this->content = $content;

        $this->role = $role instanceof MessageRole
            ? $role
            : MessageRole::tryFrom($role) ?? throw new InvalidArgumentException('Invalid message role.');
    }

    /**
     * Attempt to create a new message instance from the given value.
     */
    public static function tryFrom(mixed $message): static
    {
        return match (true) {
            $message instanceof self => $message,
            is_array($message) => new static($message['role'], $message['content']),
            is_object($message) => new static($message->role, $message->content),
            default => throw new InvalidArgumentException('Unable to create message from given value.'),
        };
    }
}
