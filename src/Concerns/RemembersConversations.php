<?php

namespace Laravel\Ai\Concerns;

use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;

trait RemembersConversations
{
    protected ?string $conversationId = null;

    protected ?object $conversationUser = null;

    /**
     * @var array<string, mixed>
     */
    protected array $conversationAttributes = [];

    /**
     * Start a new conversation for the given participant.
     */
    public function forParticipant(object $participant): static
    {
        $this->conversationId = null;
        $this->conversationUser = $participant;

        return $this;
    }

    /**
     * Start a new conversation for the given user.
     */
    public function forUser($user): static
    {
        return $this->forParticipant($user);
    }

    /**
     * Continue an existing conversation, optionally as the given user.
     */
    public function continue(string $conversationId, ?object $as = null): static
    {
        $this->conversationId = $conversationId;
        $this->conversationUser = $as;

        return $this;
    }

    /**
     * Continue an existing conversation as the given user.
     */
    public function continueLastConversation(object $as): static
    {
        $this->conversationUser = $as;

        $this->conversationId = resolve(ConversationStore::class)
            ->latestConversationId(Conversation::participantType($as), Conversation::participantKey($as));

        return $this;
    }

    /**
     * Set the extra columns to persist when a new conversation is created.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function withConversationAttributes(array $attributes): static
    {
        $this->conversationAttributes = array_merge($this->conversationAttributes, $attributes);

        return $this;
    }

    /**
     * Get the extra columns to persist when a new conversation is created.
     *
     * @return array<string, mixed>
     */
    public function conversationAttributes(): array
    {
        return $this->conversationAttributes;
    }

    /**
     * Get the list of messages comprising the conversation so far.
     */
    public function messages(): iterable
    {
        if (! $this->conversationId) {
            return [];
        }

        return resolve(ConversationStore::class)
            ->getLatestConversationMessages(
                $this->conversationId,
                $this->maxConversationMessages()
            )->all();
    }

    /**
     * Get the maximum number of conversation messages to include in context.
     */
    protected function maxConversationMessages(): int
    {
        return 100;
    }

    /**
     * Get the UUID for the current conversation, if applicable.
     */
    public function currentConversation(): ?string
    {
        return $this->conversationId;
    }

    /**
     * Determine if the conversation has a participant and is thus being remembered.
     */
    public function hasConversationParticipant(): bool
    {
        return $this->conversationUser !== null;
    }

    /**
     * Get the user having the current conversation.
     */
    public function conversationParticipant(): ?object
    {
        return $this->conversationUser;
    }
}
