<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\ConversationStore;
use Tests\Feature\Agents\AssistantAgent;
use Tests\TestCase;

class MultiTenancyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Enable multi-tenancy for these tests
        config(['ai.multi_tenancy.enabled' => true]);

        // Run migrations
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    public function test_conversation_store_can_set_tenant_context(): void
    {
        $store = resolve(ConversationStore::class);

        $this->assertNull($store->currentTenant());
        $this->assertFalse($store->hasTenantContext());

        $store->forTenant(1);

        $this->assertEquals(1, $store->currentTenant());
        $this->assertTrue($store->hasTenantContext());
    }

    public function test_conversation_store_scopes_queries_by_tenant(): void
    {
        $store = resolve(ConversationStore::class);

        // Create conversations for tenant 1
        $store->forTenant(1);
        $conv1 = $store->storeConversation(100, 'Tenant 1 Conversation');

        // Create conversations for tenant 2
        $store->forTenant(2);
        $conv2 = $store->storeConversation(100, 'Tenant 2 Conversation');

        // Verify tenant 1 only sees their conversation
        $store->forTenant(1);
        $latestForTenant1 = $store->latestConversationId(100);
        $this->assertEquals($conv1, $latestForTenant1);
        $this->assertNotEquals($conv2, $latestForTenant1);

        // Verify tenant 2 only sees their conversation
        $store->forTenant(2);
        $latestForTenant2 = $store->latestConversationId(100);
        $this->assertEquals($conv2, $latestForTenant2);
        $this->assertNotEquals($conv1, $latestForTenant2);

        // Verify in database that conversations have correct tenant_id
        $tenantColumn = config('ai.multi_tenancy.column', 'tenant_id');
        $record1 = DB::table('agent_conversations')->where('id', $conv1)->first();
        $record2 = DB::table('agent_conversations')->where('id', $conv2)->first();
        
        $this->assertEquals(1, $record1->{$tenantColumn});
        $this->assertEquals(2, $record2->{$tenantColumn});
    }

    public function test_messages_are_scoped_by_tenant(): void
    {
        $store = resolve(ConversationStore::class);

        // Tenant 1 creates a conversation
        $store->forTenant(1);
        $conv1 = $store->storeConversation(100, 'Tenant 1 Chat');

        // Tenant 2 creates a conversation
        $store->forTenant(2);
        $conv2 = $store->storeConversation(100, 'Tenant 2 Chat');

        // Add messages directly to database for tenant 1
        $store->forTenant(1);
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'conversation_id' => $conv1,
            'tenant_id' => 1,
            'user_id' => 100,
            'agent' => 'TestAgent',
            'role' => 'user',
            'content' => 'Hello from tenant 1',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add messages directly to database for tenant 2
        $store->forTenant(2);
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'conversation_id' => $conv2,
            'tenant_id' => 2,
            'user_id' => 100,
            'agent' => 'TestAgent',
            'role' => 'user',
            'content' => 'Hello from tenant 2',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify tenant 1 only sees their messages
        $store->forTenant(1);
        $messages1 = $store->getLatestConversationMessages($conv1, 10);
        $this->assertCount(1, $messages1);

        // Verify tenant 2 only sees their messages
        $store->forTenant(2);
        $messages2 = $store->getLatestConversationMessages($conv2, 10);
        $this->assertCount(1, $messages2);

        // Verify tenant 1 cannot access tenant 2's messages
        $store->forTenant(1);
        $crossTenantMessages = $store->getLatestConversationMessages($conv2, 10);
        $this->assertCount(0, $crossTenantMessages);
    }

    public function test_agent_can_use_for_tenant_method(): void
    {
        AssistantAgent::fake(['Test response']);

        $agent = new class extends AssistantAgent {
            use \Laravel\Ai\Concerns\RemembersConversations;
            use \Laravel\Ai\Concerns\HasTenantContext;

            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }
        };

        $this->assertFalse($agent->hasTenantContext());

        $agent->forTenant(5);

        $this->assertTrue($agent->hasTenantContext());
        $this->assertEquals(5, $agent->currentTenant());
    }

    public function test_agent_tenant_context_propagates_to_store(): void
    {
        AssistantAgent::fake(['Test response']);

        $agent = new class extends AssistantAgent {
            use \Laravel\Ai\Concerns\RemembersConversations;
            use \Laravel\Ai\Concerns\HasTenantContext;

            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }
        };

        $user = (object) ['id' => 1];

        // Set tenant context on agent
        $agent->forTenant(10)->forUser($user);

        // Trigger conversation storage through messages() method
        $agent->continueLastConversation($user);

        // Verify the store received tenant context from agent
        $store = resolve(ConversationStore::class);
        $this->assertEquals(10, $store->currentTenant()); // Tenant context was propagated
    }

    public function test_for_tenant_method_is_chainable(): void
    {
        AssistantAgent::fake(['Test response']);

        $agent = new class extends AssistantAgent {
            use \Laravel\Ai\Concerns\RemembersConversations;
            use \Laravel\Ai\Concerns\HasTenantContext;

            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }
        };

        $user = (object) ['id' => 1];

        // Test chaining
        $result = $agent->forTenant(1)->forUser($user);

        $this->assertSame($agent, $result);
        $this->assertEquals(1, $agent->currentTenant());
        $this->assertEquals($user, $agent->conversationParticipant());
    }

    public function test_queries_work_without_tenant_context_when_multi_tenancy_enabled(): void
    {
        $store = resolve(ConversationStore::class);

        // Don't set tenant context - should still work
        $convId = $store->storeConversation(100, 'No Tenant Conversation');

        $this->assertNotNull($convId);

        // Should be able to retrieve it
        $latestConv = $store->latestConversationId(100);
        $this->assertEquals($convId, $latestConv);

        // Verify tenant_id column is null when forTenant() was not called
        $record = DB::table('agent_conversations')->where('id', $convId)->first();
        $tenantColumn = config('ai.multi_tenancy.column', 'tenant_id');
        $this->assertNull($record->{$tenantColumn});
    }

    public function test_multiple_tenants_can_have_same_user_id(): void
    {
        $store = resolve(ConversationStore::class);

        // Tenant 1, User 100
        $store->forTenant(1);
        $conv1 = $store->storeConversation(100, 'Tenant 1 User 100');

        // Tenant 2, User 100 (same user ID, different tenant)
        $store->forTenant(2);
        $conv2 = $store->storeConversation(100, 'Tenant 2 User 100');

        // Each tenant should only see their own conversation
        $store->forTenant(1);
        $this->assertEquals($conv1, $store->latestConversationId(100));

        $store->forTenant(2);
        $this->assertEquals($conv2, $store->latestConversationId(100));
    }

    public function test_tenant_context_can_be_changed(): void
    {
        $store = resolve(ConversationStore::class);

        $store->forTenant(1);
        $this->assertEquals(1, $store->currentTenant());

        $store->forTenant(2);
        $this->assertEquals(2, $store->currentTenant());

        $store->forTenant(3);
        $this->assertEquals(3, $store->currentTenant());
    }

    public function test_tenant_id_supports_string_identifiers(): void
    {
        $store = resolve(ConversationStore::class);

        $store->forTenant('tenant-uuid-123');
        $this->assertEquals('tenant-uuid-123', $store->currentTenant());

        $convId = $store->storeConversation(100, 'String Tenant ID');
        $this->assertNotNull($convId);
    }
}
