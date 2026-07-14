<?php

namespace Laravel\Ai\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Ai\Models\Conversation;

trait HasConversations
{
    /**
     * Get the conversations for the model.
     *
     * @return MorphMany<Conversation, $this>|HasMany<Conversation, $this>
     */
    public function conversations(): MorphMany|HasMany
    {
        if (Conversation::hasParticipantType()) {
            return $this->morphMany(Conversation::class, 'participant', 'participant_type', Conversation::ownerColumn());
        }

        return $this->hasMany(Conversation::class, Conversation::ownerColumn());
    }
}
