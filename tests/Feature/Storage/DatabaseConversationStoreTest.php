<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Storage\DatabaseConversationStore;

afterEach(function () {
    foreach ([
        'custom_conversations',
        'custom_conversation_messages',
        'agent_conversation_messages',
        'agent_conversations',
    ] as $table) {
        Schema::dropIfExists($table);
    }
});

test('the default config points at agent_conversations and agent_conversation_messages', function () {
    expect(config('ai.storage.tables.conversations'))->toBe('agent_conversations');
    expect(config('ai.storage.tables.messages'))->toBe('agent_conversation_messages');
});

test('it writes conversations to the default tables when no override is configured', function () {
    createConversationSchema('agent_conversations', 'agent_conversation_messages');

    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Hello');

    expect(DB::table('agent_conversations')->where('id', $conversationId)->exists())->toBeTrue();
});

test('it writes conversations to the configured tables when overridden', function () {
    config([
        'ai.storage.tables.conversations' => 'custom_conversations',
        'ai.storage.tables.messages' => 'custom_conversation_messages',
    ]);

    createConversationSchema('custom_conversations', 'custom_conversation_messages');

    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Hello');

    expect(DB::table('custom_conversations')->where('id', $conversationId)->exists())->toBeTrue();
    expect(Schema::hasTable('agent_conversations'))->toBeFalse();
});

test('the migration creates the tables under the configured names', function () {
    config([
        'ai.storage.tables.conversations' => 'custom_conversations',
        'ai.storage.tables.messages' => 'custom_conversation_messages',
    ]);

    $migration = require __DIR__.'/../../../database/migrations/2026_01_11_000001_create_agent_conversations_table.php';
    $migration->up();

    expect(Schema::hasTable('custom_conversations'))->toBeTrue()
        ->and(Schema::hasTable('custom_conversation_messages'))->toBeTrue()
        ->and(Schema::hasTable('agent_conversations'))->toBeFalse()
        ->and(Schema::hasTable('agent_conversation_messages'))->toBeFalse();
});

function createConversationSchema(string $conversationsTable, string $messagesTable): void
{
    Schema::create($conversationsTable, function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->foreignId('user_id')->nullable();
        $table->string('title');
        $table->timestamps();
    });

    Schema::create($messagesTable, function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->string('conversation_id', 36)->index();
        $table->foreignId('user_id')->nullable();
        $table->string('agent');
        $table->string('role', 25);
        $table->text('content');
        $table->text('attachments');
        $table->text('tool_calls');
        $table->text('tool_results');
        $table->text('usage');
        $table->text('meta');
        $table->timestamps();
    });
}
