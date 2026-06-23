<?php

namespace Laravel\Ai\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Ai;

trait HasConversations
{
    /**
     * Get the conversations for the model.
     *
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Ai::conversationModel(), 'user_id');
    }
}
