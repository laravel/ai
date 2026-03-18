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
     * The provider-specific options for the message.
     *
     * @var array<string, mixed>
     */
    public array $providerOptions = [];

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
     * Set provider-specific options on the message.
     *
     * These options are passed through to the underlying provider's message
     * representation. For example, Anthropic supports cache control:
     *
     *     $message->withProviderOptions(['cacheType' => 'ephemeral'])
     *
     * @param  array<string, mixed>  $options
     */
    public function withProviderOptions(array $options): static
    {
        $this->providerOptions = $options;

        return $this;
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
