<?php

namespace Laravel\Ai\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

#[WithoutIncrementing]
class Conversation extends Model
{
    /**
     * The data type of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Get the messages for the conversation.
     *
     * @return HasMany<ConversationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class, 'conversation_id');
    }

    /**
     * Get the table associated with the model.
     */
    #[\Override]
    public function getTable(): string
    {
        return config('ai.conversations.tables.conversations', 'agent_conversations');
    }

    /**
     * Get the database connection for the model.
     */
    #[\Override]
    public function getConnectionName(): ?string
    {
        return config('ai.conversations.connection');
    }

    /**
     * Resolve the participant_type discriminator to record for the participant.
     */
    public static function participantType(?object $participant): ?string
    {
        if ($participant === null) {
            return null;
        }

        return method_exists($participant, 'getMorphClass')
            ? $participant->getMorphClass()
            : $participant::class;
    }

    /**
     * Resolve the conversation owner column, supporting legacy user_id schemas.
     */
    public static function ownerColumn(): string
    {
        return static::schema()->hasColumn(static::tableName(), 'participant_id') ? 'participant_id' : 'user_id';
    }

    /**
     * Determine whether the conversations table records a participant type.
     */
    public static function hasParticipantType(): bool
    {
        return static::schema()->hasColumn(static::tableName(), 'participant_type');
    }

    /**
     * Get the configured connection's schema builder.
     */
    protected static function schema()
    {
        return Schema::connection(config('ai.conversations.connection'));
    }

    /**
     * Get the conversations table name.
     */
    protected static function tableName(): string
    {
        return config('ai.conversations.tables.conversations', 'agent_conversations');
    }
}
