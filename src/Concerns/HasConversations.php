<?php

namespace Laravel\Ai\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\Conversation;

trait HasConversations
{
    /**
     * @return Collection<int, Conversation>
     */
    public function conversations(int $limit = 25): Collection
    {
        return resolve(ConversationStore::class)->getConversations($this->id, $limit);
    }
}
