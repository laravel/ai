<?php

namespace Laravel\Ai\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $role
 * @property ?string $content
 * @property ?array $attachments
 * @property ?array $steps
 * @property-read array $tool_calls
 * @property-read array $provider_tool_calls
 * @property-read array $tool_results
 * @property ?Carbon $approval_requested_at
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
    protected $appends = ['tool_calls', 'tool_results', 'provider_tool_calls'];

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
        'approval_requested_at' => 'datetime',
    ];

    /**
     * The tool calls made across every step of the turn, in step order.
     */
    protected function toolCalls(): Attribute
    {
        return Attribute::get(fn (): array => Arr::collapse(array_column($this->steps ?? [], 'tool_calls')));
    }

    /**
     * The provider-hosted tool calls made across every step of the turn, in step order.
     */
    protected function providerToolCalls(): Attribute
    {
        return Attribute::get(fn (): array => Arr::collapse(array_column($this->steps ?? [], 'provider_tool_calls')));
    }

    /**
     * The tool results recorded across every step of the turn, in step order.
     */
    protected function toolResults(): Attribute
    {
        return Attribute::get(fn (): array => array_values(array_map(
            fn (array $toolCall): array => Arr::only($toolCall, ['id', 'name', 'arguments', 'result', 'result_id', 'denied', 'failed']),
            array_filter($this->tool_calls, fn (array $toolCall): bool => array_key_exists('result', $toolCall)),
        )));
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
