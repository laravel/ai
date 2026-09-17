<?php

namespace Laravel\Ai\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

/**
 * @property string $id
 * @property string $role
 * @property ?string $content
 * @property ?array $attachments
 * @property ?array $steps
 * @property-read array $tool_calls
 * @property-read array $tool_results
 * @property ?array $approval_state
 */
#[WithoutIncrementing]
class ConversationMessage extends Model
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
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = ['tool_calls', 'tool_results'];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'attachments' => 'array',
        'steps' => 'array',
        'usage' => 'array',
        'meta' => 'array',
        'approval_state' => 'array',
    ];

    /**
     * The tool calls made across every step of the turn, in step order.
     */
    protected function toolCalls(): Attribute
    {
        return Attribute::get(fn (): array => Arr::collapse(array_column($this->steps ?? [], 'tool_calls')));
    }

    /**
     * The tool results recorded across every step of the turn, in step order.
     */
    protected function toolResults(): Attribute
    {
        return Attribute::get(fn (): array => Arr::collapse(array_column($this->steps ?? [], 'tool_results')));
    }

    /**
     * Get the conversation that owns the message.
     *
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * Get the table associated with the model.
     */
    #[\Override]
    public function getTable(): string
    {
        return config('ai.conversations.tables.messages', 'agent_conversation_messages');
    }

    /**
     * Get the database connection for the model.
     */
    #[\Override]
    public function getConnectionName(): ?string
    {
        return config('ai.conversations.connection');
    }
}
