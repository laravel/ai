<?php

namespace Laravel\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Conversation extends Model
{
    /**
     * Indicates if the model's ID is auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

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
    public function getTable(): string
    {
        return config('ai.conversations.tables.conversations', 'agent_conversations');
    }

    /**
     * Get the database connection for the model.
     */
    public function getConnectionName(): ?string
    {
        return config('ai.conversations.connection');
    }

    /**
     * Resolve the participant model that owns conversations with a null type.
     */
    public static function configuredParticipantModel(): string
    {
        return config('ai.conversations.participant_model') ?: config('auth.providers.users.model', 'App\Models\User');
    }

    /**
     * Resolve the participant_type discriminator to record for the participant.
     *
     * Null signals the configured user model (or no participant); any other
     * model records its morph class so ids that collide across models no longer
     * share history.
     */
    public static function participantType(?object $participant): ?string
    {
        if ($participant === null) {
            return null;
        }

        if ($participant::class === static::configuredParticipantModel()) {
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
