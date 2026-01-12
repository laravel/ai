<?php

namespace Laravel\Ai\Concerns;

use Illuminate\Support\Facades\DB;
use Laravel\Ai\Messages\Message;

trait RecordsConversations
{
    protected ?string $conversationUuid = null;

    protected ?object $conversationUser = null;

    /**
     * Start a new conversation for the given user.
     */
    public function forUser($user): static
    {
        $this->conversationUser = $user;

        return $this;
    }

    /**
     * Continue an existing conversation.
     */
    public function continue(string $conversationUuid, object $as): static
    {
        $this->conversationUuid = $conversationUuid;
        $this->conversationUser = $as;

        return $this;
    }

    /**
     * Get the list of messages comprising the conversation so far.
     */
    public function messages(): iterable
    {
        if (! $this->conversationUuid) {
            return [];
        }

        $conversation = DB::table('agent_conversations')
            ->where('uuid', $this->conversationUuid)
            ->first();

        if (! $conversation) {
            return [];
        }

        return DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($m) => new Message($m->role, $m->content))
            ->all();
    }

    /**
     * Determine if conversation recording is active.
     */
    public function recordsConversations(): bool
    {
        return $this->conversationUser !== null;
    }

    /**
     * Get the conversation UUID.
     */
    public function getConversationUuid(): ?string
    {
        return $this->conversationUuid;
    }

    /**
     * Get the conversation user.
     */
    public function getConversationUser()
    {
        return $this->conversationUser;
    }

    /**
     * Set the conversation UUID.
     */
    public function setConversationUuid(string $uuid): void
    {
        $this->conversationUuid = $uuid;
    }
}
