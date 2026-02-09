<?php

namespace Tests\Feature;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Tests\TestCase;
use Tests\Feature\Stubs\ConversationStoreStubs;

class DatabaseConversationStoreTest extends TestCase
{
    private const DEFAULT_USER_ID = 1;

    protected DatabaseConversationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new DatabaseConversationStore;
        $this->clearTables();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(Str::random(32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        $migrationsPath = __DIR__.'/../../database/migrations';
        if (file_exists($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }
    }

    protected function clearTables(): void
    {
        if (Schema::hasTable('agent_conversation_messages')) {
            DB::table('agent_conversation_messages')->delete();
        }
        if (Schema::hasTable('agent_conversations')) {
            DB::table('agent_conversations')->delete();
        }
    }

    /** @return object{id: string, content: string, tool_calls: string, tool_results: string, ...} */
    protected function getMessageRow(string $messageId): ?object
    {
        return DB::table('agent_conversation_messages')->where('id', $messageId)->first();
    }

    protected function createConversation(int $userId = self::DEFAULT_USER_ID, string $title = 'Test Conversation'): string
    {
        return $this->store->storeConversation($userId, $title);
    }

    protected function createPrompt(string $prompt, array $attachments = []): AgentPrompt
    {
        return new AgentPrompt(
            agent: ConversationStoreStubs::agent(),
            prompt: $prompt,
            attachments: $attachments,
            provider: ConversationStoreStubs::textProvider(),
            model: 'test-model'
        );
    }

    /**
     * @param  array<int, array{id: string, name: string, arguments?: array}>  $toolCallsData
     * @param  array<int, array{tool_call_id: string, content: string}>  $toolResultsData
     */
    protected function createResponse(
        string $text,
        array $toolCallsData = [],
        array $toolResultsData = []
    ): AgentResponse {
        $response = new AgentResponse(
            invocationId: 'test-invocation-id',
            text: $text,
            usage: new Usage(promptTokens: 10, completionTokens: 20),
            meta: new Meta(provider: 'test', model: 'test-model')
        );

        if ($toolCallsData !== [] || $toolResultsData !== []) {
            $toolCalls = Collection::make($toolCallsData)->map(
                fn (array $t) => new ToolCall(
                    id: $t['id'],
                    name: $t['name'],
                    arguments: $t['arguments'] ?? []
                )
            );
            $response->withToolCallsAndResults($toolCalls, Collection::make($toolResultsData));
        }

        return $response;
    }

    protected function insertLegacyMessage(string $conversationId, int $userId, string $content, string $messageId = 'legacy-message-id'): void
    {
        DB::table('agent_conversation_messages')->insert([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'agent' => 'TestAgent',
            'role' => 'user',
            'content' => $content,
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function withEncryption(bool $enabled): void
    {
        Config::set('ai.conversations.encrypt_sensitive_fields', $enabled);
    }

    public function test_user_message_content_is_encrypted_when_encryption_enabled(): void
    {
        $this->withEncryption(true);

        $conversationId = $this->createConversation();
        $messageId = $this->store->storeUserMessage($conversationId, self::DEFAULT_USER_ID, $this->createPrompt('Sensitive user message'));

        $row = $this->getMessageRow($messageId);
        $this->assertNotNull($row);
        $this->assertNotEquals('Sensitive user message', $row->content);
        $this->assertNotEmpty($row->content);
        $this->assertEquals('Sensitive user message', Crypt::decryptString($row->content));
    }

    public function test_user_message_content_is_not_encrypted_when_encryption_disabled(): void
    {
        $this->withEncryption(false);

        $conversationId = $this->createConversation();
        $messageId = $this->store->storeUserMessage($conversationId, self::DEFAULT_USER_ID, $this->createPrompt('Plain text message'));

        $row = $this->getMessageRow($messageId);
        $this->assertNotNull($row);
        $this->assertEquals('Plain text message', $row->content);
    }

    public function test_assistant_message_content_is_encrypted_when_encryption_enabled(): void
    {
        $this->withEncryption(true);

        $conversationId = $this->createConversation();
        $prompt = $this->createPrompt('User prompt');
        $messageId = $this->store->storeAssistantMessage($conversationId, self::DEFAULT_USER_ID, $prompt, $this->createResponse('Sensitive assistant response'));

        $row = $this->getMessageRow($messageId);
        $this->assertNotNull($row);
        $this->assertNotEquals('Sensitive assistant response', $row->content);
    }

    public function test_assistant_message_tool_calls_are_encrypted_when_encryption_enabled(): void
    {
        $this->withEncryption(true);

        $conversationId = $this->createConversation();
        $prompt = $this->createPrompt('User prompt');
        $response = $this->createResponse('Response', [['id' => 'call_1', 'name' => 'test_function', 'arguments' => ['key' => 'value']]]);
        $messageId = $this->store->storeAssistantMessage($conversationId, self::DEFAULT_USER_ID, $prompt, $response);

        $row = $this->getMessageRow($messageId);
        $this->assertNotNull($row);
        $this->assertNotEquals('[]', $row->tool_calls);
        $this->assertStringNotContainsString('call_1', $row->tool_calls);
        $this->assertStringNotContainsString('test_function', $row->tool_calls);
    }

    public function test_assistant_message_tool_results_are_encrypted_when_encryption_enabled(): void
    {
        $this->withEncryption(true);

        $conversationId = $this->createConversation();
        $prompt = $this->createPrompt('User prompt');
        $response = $this->createResponse('Response', [], [['tool_call_id' => 'call_1', 'content' => 'Sensitive tool result']]);
        $messageId = $this->store->storeAssistantMessage($conversationId, self::DEFAULT_USER_ID, $prompt, $response);

        $row = $this->getMessageRow($messageId);
        $this->assertNotNull($row);
        $this->assertNotEquals('[]', $row->tool_results);
        $this->assertStringNotContainsString('Sensitive tool result', $row->tool_results);
        $this->assertStringNotContainsString('call_1', $row->tool_results);
    }

    public function test_assistant_message_fields_are_not_encrypted_when_encryption_disabled(): void
    {
        $this->withEncryption(false);

        $conversationId = $this->createConversation();
        $prompt = $this->createPrompt('User prompt');
        $response = $this->createResponse(
            'Plain response',
            [['id' => 'call_1', 'name' => 'test', 'arguments' => []]],
            [['tool_call_id' => 'call_1', 'content' => 'result']]
        );
        $messageId = $this->store->storeAssistantMessage($conversationId, self::DEFAULT_USER_ID, $prompt, $response);

        $row = $this->getMessageRow($messageId);
        $this->assertNotNull($row);
        $this->assertEquals('Plain response', $row->content);
        $this->assertStringContainsString('call_1', $row->tool_calls);
        $this->assertStringContainsString('result', $row->tool_results);
    }

    public function test_content_is_decrypted_when_retrieving_messages(): void
    {
        $this->withEncryption(true);

        $conversationId = $this->createConversation();
        $prompt = $this->createPrompt('User message');
        $this->store->storeUserMessage($conversationId, self::DEFAULT_USER_ID, $prompt);
        $this->store->storeAssistantMessage($conversationId, self::DEFAULT_USER_ID, $prompt, $this->createResponse('Assistant response'));

        $messages = $this->store->getLatestConversationMessages($conversationId, 10);

        $this->assertCount(2, $messages);
        $this->assertEquals('user', $messages->first()->role->value);
        $this->assertEquals('User message', $messages->first()->content);
        $this->assertEquals('assistant', $messages->last()->role->value);
        $this->assertEquals('Assistant response', $messages->last()->content);
    }

    public function test_tool_calls_and_tool_results_are_encrypted_and_decryptable(): void
    {
        $this->withEncryption(true);

        $conversationId = $this->createConversation();
        $prompt = $this->createPrompt('User prompt');
        $response = $this->createResponse(
            'Response',
            [['id' => 'call_1', 'name' => 'test_function', 'arguments' => []]],
            [['tool_call_id' => 'call_1', 'content' => 'sensitive result']]
        );
        $messageId = $this->store->storeAssistantMessage($conversationId, self::DEFAULT_USER_ID, $prompt, $response);

        $row = $this->getMessageRow($messageId);
        $this->assertNotNull($row);
        $this->assertNotEquals('[]', $row->tool_calls);
        $this->assertNotEquals('[]', $row->tool_results);
        $this->assertStringNotContainsString('call_1', $row->tool_calls);
        $this->assertStringNotContainsString('sensitive result', $row->tool_results);

        $this->assertStringContainsString('call_1', Crypt::decryptString($row->tool_calls));
        $this->assertStringContainsString('sensitive result', Crypt::decryptString($row->tool_results));
    }

    public function test_legacy_plaintext_content_is_handled_gracefully(): void
    {
        $this->withEncryption(true);

        $conversationId = $this->createConversation();
        $this->insertLegacyMessage($conversationId, self::DEFAULT_USER_ID, 'Legacy plaintext message');

        $messages = $this->store->getLatestConversationMessages($conversationId, 10);

        $this->assertCount(1, $messages);
        $this->assertEquals('Legacy plaintext message', $messages->first()->content);
    }

    public function test_empty_content_is_handled_correctly(): void
    {
        $this->withEncryption(true);

        $conversationId = $this->createConversation();
        $this->store->storeUserMessage($conversationId, self::DEFAULT_USER_ID, $this->createPrompt(''));

        $messages = $this->store->getLatestConversationMessages($conversationId, 10);
        $this->assertCount(1, $messages);
        $this->assertEquals('', $messages->first()->content);

        // Also assert raw empty string in DB is decrypted as empty
        $this->insertLegacyMessage($conversationId, self::DEFAULT_USER_ID, '', 'empty-content-id');
        $messages = $this->store->getLatestConversationMessages($conversationId, 10);
        $this->assertCount(2, $messages);
        $this->assertEquals('', $messages->last()->content);
    }

    public function test_encryption_config_is_memoized(): void
    {
        $this->withEncryption(true);

        $conversationId = $this->createConversation();
        $prompt = $this->createPrompt('Test message');
        $this->store->storeUserMessage($conversationId, self::DEFAULT_USER_ID, $prompt);

        Config::set('ai.conversations.encrypt_sensitive_fields', false);
        $messageId2 = $this->store->storeUserMessage($conversationId, self::DEFAULT_USER_ID, $prompt);

        $row = $this->getMessageRow($messageId2);
        $this->assertNotNull($row);
        $this->assertNotEquals('Test message', $row->content);
    }

    public function test_multiple_messages_are_retrieved_in_correct_order(): void
    {
        $this->withEncryption(true);

        $conversationId = $this->createConversation();
        $this->store->storeUserMessage($conversationId, self::DEFAULT_USER_ID, $this->createPrompt('Message 1'));
        $this->store->storeUserMessage($conversationId, self::DEFAULT_USER_ID, $this->createPrompt('Message 2'));
        $this->store->storeUserMessage($conversationId, self::DEFAULT_USER_ID, $this->createPrompt('Message 3'));

        $messages = $this->store->getLatestConversationMessages($conversationId, 10);

        $this->assertCount(3, $messages);
        $this->assertEquals('Message 1', $messages->first()->content);
        $this->assertEquals('Message 3', $messages->last()->content);
    }
}
