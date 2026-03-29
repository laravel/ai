<?php

namespace Tests\Unit\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Tests\TestCase;

class DatabaseConversationStoreTest extends TestCase
{
    protected DatabaseConversationStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new DatabaseConversationStore;

        $this->createTables();
    }

    protected function createTables(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('agent_conversations', function ($table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->nullable();
            $table->string('title');
            $table->timestamps();
        });

        $schema->create('agent_conversation_messages', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->string('user_id')->nullable();
            $table->string('agent');
            $table->string('role');
            $table->text('content')->nullable();
            $table->json('attachments');
            $table->json('tool_calls');
            $table->json('tool_results');
            $table->json('usage');
            $table->json('meta');
            $table->timestamps();
        });
    }

    protected function insertAssistantMessage(string $conversationId, array $toolCalls, array $toolResults = []): void
    {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'user_id' => '1',
            'agent' => 'TestAgent',
            'role' => 'assistant',
            'content' => 'test response',
            'attachments' => '[]',
            'tool_calls' => json_encode($toolCalls),
            'tool_results' => json_encode($toolResults),
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function createConversation(): string
    {
        $id = (string) Str::uuid7();

        DB::table('agent_conversations')->insert([
            'id' => $id,
            'user_id' => '1',
            'title' => 'Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_tool_call_with_arguments_key(): void
    {
        $conversationId = $this->createConversation();

        $this->insertAssistantMessage($conversationId, [
            ['id' => 'tc_1', 'name' => 'search', 'arguments' => ['query' => 'test'], 'result_id' => 'r_1'],
        ]);

        $messages = $this->store->getLatestConversationMessages($conversationId, 10);

        $toolCall = $messages->first()->toolCalls->first();
        $this->assertInstanceOf(ToolCall::class, $toolCall);
        $this->assertEquals(['query' => 'test'], $toolCall->arguments);
    }

    public function test_tool_call_with_input_key(): void
    {
        $conversationId = $this->createConversation();

        $this->insertAssistantMessage($conversationId, [
            ['id' => 'tc_1', 'name' => 'search', 'input' => ['query' => 'test'], 'result_id' => 'r_1'],
        ]);

        $messages = $this->store->getLatestConversationMessages($conversationId, 10);

        $toolCall = $messages->first()->toolCalls->first();
        $this->assertInstanceOf(ToolCall::class, $toolCall);
        $this->assertEquals(['query' => 'test'], $toolCall->arguments);
    }

    public function test_tool_result_with_input_key(): void
    {
        $conversationId = $this->createConversation();

        $this->insertAssistantMessage(
            $conversationId,
            [['id' => 'tc_1', 'name' => 'search', 'input' => ['query' => 'test'], 'result_id' => 'r_1']],
            [['id' => 'tr_1', 'name' => 'search', 'input' => ['query' => 'test'], 'result' => 'found it', 'result_id' => 'r_1']]
        );

        $messages = $this->store->getLatestConversationMessages($conversationId, 10);

        $toolResultMessage = $messages->last();
        $this->assertInstanceOf(ToolResultMessage::class, $toolResultMessage);

        $toolResult = $toolResultMessage->toolResults->first();
        $this->assertInstanceOf(ToolResult::class, $toolResult);
        $this->assertEquals(['query' => 'test'], $toolResult->arguments);
    }

    public function test_tool_call_with_neither_key_returns_empty_array(): void
    {
        $conversationId = $this->createConversation();

        $this->insertAssistantMessage($conversationId, [
            ['id' => 'tc_1', 'name' => 'search', 'result_id' => 'r_1'],
        ]);

        $messages = $this->store->getLatestConversationMessages($conversationId, 10);

        $toolCall = $messages->first()->toolCalls->first();
        $this->assertEquals([], $toolCall->arguments);
    }
}
