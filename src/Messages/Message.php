<?php

namespace Laravel\Ai\Messages;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Files\File;

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
            : (MessageRole::tryFrom($role) ?? throw new InvalidArgumentException('Invalid message role.'));
    }

    /**
     * Attempt to create a new message instance from the given value.
     */
    public static function tryFrom(mixed $message): self
    {
        return match (true) {
            $message instanceof self => $message,
            is_array($message) && isset($message['parts']) => static::fromUiMessage($message),
            is_array($message) => new self($message['role'], $message['content']),
            is_object($message) => new self($message->role, $message->content),
            default => throw new InvalidArgumentException('Unable to create message from given value.'),
        };
    }

    /**
     * Create a message from a Vercel AI SDK UI message (parts-based) array.
     *
     * @param  array<string, mixed>  $message
     */
    protected static function fromUiMessage(array $message): self
    {
        $parts = new Collection($message['parts']);

        $text = $parts->where('type', 'text')->pluck('text')->implode(PHP_EOL.PHP_EOL);

        return match ($message['role'] ?? null) {
            'user' => new UserMessage($text, $parts
                ->where('type', 'file')
                ->map(fn (array $part) => File::fromUiMessagePart($part))
                ->filter()
                ->values()),
            'assistant' => new AssistantMessage($text),
            default => throw new InvalidArgumentException('Invalid message role.'),
        };
    }
}
