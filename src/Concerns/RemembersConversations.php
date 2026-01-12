<?php

namespace Laravel\Ai\Concerns;

use Illuminate\Support\Facades\DB;
use Laravel\Ai\Messages\Message;

trait RemembersConversations
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
     * Continue an existing conversation as the given user.
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
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn ($m) => new Message($m->role, $m->content))
            ->all();
    }

    /**
     * Get the UUID for the current conversation, if applicable.
     */
    public function currentConversation(): ?string
    {
        return $this->conversationUuid;
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
