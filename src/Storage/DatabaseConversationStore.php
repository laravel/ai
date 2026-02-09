<?php

namespace Laravel\Ai\Storage;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

class DatabaseConversationStore implements ConversationStore
{
    /**
     * Memoize the encryption enabled status to avoid repeated config calls.
     */
    protected ?bool $encryptionEnabled = null;

    /**
     * Determine if encryption is enabled for conversation fields.
     */
    protected function isEncryptionEnabled(): bool
    {
        return $this->encryptionEnabled ??= config('ai.conversations.encrypt_sensitive_fields', true);
    }

    /**
     * Encrypt a value when conversation encryption is enabled.
     */
    private function encryptField(string $value): string
    {
        return $this->isEncryptionEnabled() ? Crypt::encryptString($value) : $value;
    }

    /**
     * Decrypt a value when conversation encryption is enabled.
     * Returns the raw value if decryption fails (e.g. legacy plaintext).
     */
    private function decryptField(?string $value): string
    {
        if (empty($value) || ! $this->isEncryptionEnabled()) {
            return $value ?? '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            // Return raw value for legacy plaintext data
            return $value;
        }
    }

    /**
     * Get the most recent conversation ID for a given user.
     */
    public function latestConversationId(string|int $userId): ?string
    {
        return DB::table('agent_conversations')
            ->where('user_id', $userId)
            ->orderBy('updated_at', 'desc')
            ->first()?->id;
    }

    /**
     * Store a new conversation and return its ID.
     */
    public function storeConversation(string|int $userId, string $title): string
    {
        $conversationId = (string) Str::uuid7();

        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $userId,
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $conversationId;
    }

    /**
     * Store a new user message for the given conversation and return its ID.
     */
    public function storeUserMessage(string $conversationId, string|int $userId, AgentPrompt $prompt): string
    {
        $messageId = (string) Str::uuid7();

        DB::table('agent_conversation_messages')->insert([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'agent' => $prompt->agent::class,
            'role' => 'user',
            'content' => $this->encryptField($prompt->prompt ?? ''),
            'attachments' => $prompt->attachments->toJson(),
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $messageId;
    }

    /**
     * Store a new assistant message for the given conversation and return its ID.
     */
    public function storeAssistantMessage(string $conversationId, string|int $userId, AgentPrompt $prompt, AgentResponse $response): string
    {
        $messageId = (string) Str::uuid7();

        DB::table('agent_conversation_messages')->insert([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'agent' => $prompt->agent::class,
            'role' => 'assistant',
            'content' => $this->encryptField((string) ($response->text ?? '')),
            'attachments' => '[]',
            'tool_calls' => $this->encryptField(json_encode($response->toolCalls ?? [])),
            'tool_results' => $this->encryptField(json_encode($response->toolResults ?? [])),
            'usage' => json_encode($response->usage),
            'meta' => json_encode($response->meta),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $messageId;
    }

    /**
     * Get the latest messages for the given conversation.
     *
     * @return \Illuminate\Support\Collection<int, \Laravel\Ai\Messages\Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($m) => new Message($m->role, $this->decryptField($m->content)));
    }
}
