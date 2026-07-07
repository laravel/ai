<?php

namespace Laravel\Ai\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Ai\Models\Conversation;

trait HasConversations
{
    /**
     * Get the conversations for the model.
     *
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        $relation = $this->hasMany(Conversation::class, Conversation::ownerColumn());

        if (Conversation::hasParticipantType()) {
            $relation->where('participant_type', Conversation::participantType($this));
        }

        return $relation;
    }
}
