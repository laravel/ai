<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Tests\Fixtures\Agents\AssistantAgent;

beforeEach(function () {
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge('testbench');
    DB::reconnect('testbench');

    Schema::dropIfExists('agent_conversation_messages');
    Schema::dropIfExists('agent_conversations');

    Schema::create('agent_conversations', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->foreignId('user_id')->nullable();
        $table->string('title');
        $table->timestamps();

        $table->index(['user_id', 'updated_at']);
    });

    Schema::create('agent_conversation_messages', function (Blueprint $table) {
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

        $table->index(['conversation_id', 'user_id', 'updated_at'], 'conversation_index');
        $table->index(['user_id']);
    });
});

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

it('returns the most recently active conversation after storing an assistant message', function () {
    $store = new DatabaseConversationStore;

    Carbon::setTestNow('2026-04-10 10:00:00');
    $conversationA = $store->storeConversation(1, 'Conversation A');

    Carbon::setTestNow('2026-04-10 10:01:00');
    $store->storeConversation(1, 'Conversation B');

    Carbon::setTestNow('2026-04-10 10:02:00');
    $store->storeAssistantMessage(
        $conversationA,
        1,
        new AgentPrompt(
            new AssistantAgent,
            'Hello',
            [],
            Mockery::mock(TextProvider::class),
            'test-model',
        ),
        new AgentResponse(
            'invocation-1',
            'Hi',
            new Usage,
            new Meta('openai', 'gpt-5-mini'),
        ),
    );

    expect($store->latestConversationId(1))->toBe($conversationA);
});
