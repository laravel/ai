<?php

namespace Laravel\Ai\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Ai\Models\Conversation;

trait HasConversations
{
    /**
     * Get the conversations for the model.
     *
     * @return MorphMany<Conversation, $this>
     */
    public function conversations(): MorphMany
    {
        return $this->morphMany(Conversation::class, 'participant');
    }
}
