<?php

namespace Laravel\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Throwable;

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
     * Get the participant that owns the conversation.
     *
     * @return MorphTo<Model, $this>
     */
    public function participant(): MorphTo
    {
        return $this->morphTo();
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
    public static function participantType(object $participant): string
    {
        return $participant instanceof Model
            ? $participant->getMorphClass()
            : $participant::class;
    }

    /**
     * Resolve the participant_id key to record for the participant.
     */
    public static function participantKey(object $participant): string|int
    {
        if ($participant instanceof Model) {
            return $participant->getKey();
        }

        return $participant->id ?? throw new InvalidArgumentException(
            'The conversation participant must be an Eloquent model or expose an [id] property.'
        );
    }

    /**
     * Generate a title for a conversation that starts with the given message.
     */
    public static function generateTitle(string $message, TextProvider $provider, ?string $parentInvocationId = null): string
    {
        if (! (bool) config('ai.conversations.generate_title', true)) {
            return Str::limit($message, 50, preserveWords: true);
        }

        try {
            $response = $provider->prompt(new AgentPrompt(
                new AnonymousAgent(
                    'Generate a concise 3-5 word title for a conversation that starts with the following message. Use the same language as the message. Respond with only the title, no quotes or punctuation.',
                    [],
                    [],
                ),
                Str::limit($message, 500),
                [],
                $provider,
                $provider->cheapestTextModel(),
                parentInvocationId: $parentInvocationId,
            ));

            return Str::limit($response->text, 100);
        } catch (Throwable) {
            return Str::limit($message, 100, preserveWords: true);
        }
    }
}
