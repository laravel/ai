<?php

namespace Laravel\Ai\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Ai\Models\Conversation;

trait HasConversations
{
    /**
     * Get the conversations for the model.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'user_id');
    }
}
