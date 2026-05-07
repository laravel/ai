<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Storage\DatabaseConversationStore;

test('it writes conversations to the default tables', function () {
    $store = new DatabaseConversationStore;

    $conversationId = $store->storeConversation(1, 'Hello');

    expect(DB::table('agent_conversations')->where('id', $conversationId)->where('title', 'Hello')->exists())->toBeTrue();
});

test('it writes to overridden table names from config', function () {
    Config::set('ai.conversations.tables.conversations', 'custom_conversations');
    Config::set('ai.conversations.tables.messages', 'custom_conversation_messages');

    createConversationSchema();

    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Hello');

    expect(DB::table('custom_conversations')->where('id', $conversationId)->exists())->toBeTrue()
        ->and(DB::table('agent_conversations')->where('id', $conversationId)->exists())->toBeFalse();
});

test('it routes queries through the configured connection', function () {
    Config::set('database.connections.secondary', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    createConversationSchema('secondary');

    $store = new DatabaseConversationStore('secondary');
    $conversationId = $store->storeConversation(1, 'Hello');

    expect(DB::connection('secondary')->table('agent_conversations')->where('id', $conversationId)->exists())->toBeTrue()
        ->and(DB::table('agent_conversations')->where('id', $conversationId)->exists())->toBeFalse();
});

function createConversationSchema(?string $connection = null): void
{
    $schema = Schema::connection($connection);

    $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
    $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

    $schema->create($conversationsTable, function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->foreignId('user_id')->nullable();
        $table->string('title');
        $table->timestamps();
    });

    $schema->create($messagesTable, function (Blueprint $table) {
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
