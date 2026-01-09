<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\CreatingStore;
use Laravel\Ai\Events\StoreCreated;
use Laravel\Ai\Events\StoreDeleted;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Stores;
use Tests\TestCase;

use function Illuminate\Support\days;
use function Laravel\Ai\agent;

class StoreIntegrationTest extends TestCase
{
    protected $provider = 'openai';

    public function test_can_create_get_and_delete_store(): void
    {
        Event::fake();

        $created = Stores::create('Test Store', provider: $this->provider);

        $this->assertNotEmpty($created->id);

        Event::assertDispatched(CreatingStore::class);
        Event::assertDispatched(StoreCreated::class);

        $retrieved = Stores::get($created->id, provider: $this->provider);

        $this->assertEquals($created->id, $retrieved->id);
        $this->assertEquals('Test Store', $retrieved->name);
        $this->assertEquals(0, $retrieved->fileCounts->completed);
        $this->assertTrue(is_bool($retrieved->ready));

        $deleted = Stores::delete($created->id, provider: $this->provider);

        $this->assertTrue($deleted);

        Event::assertDispatched(StoreDeleted::class);
    }

    public function test_can_create_store_with_expiration(): void
    {
        $created = Stores::create(
            name: 'Expiring Store',
            description: 'A store that expires after 7 days of inactivity.',
            expiresWhenIdleFor: days(7),
            provider: $this->provider,
        );

        $this->assertNotEmpty($created->id);

        Stores::delete($created->id, provider: $this->provider);
    }

    public function test_can_add_and_remove_file_from_store(): void
    {
        // Create a store...
        $store = Stores::create('File Test Store', provider: $this->provider);

        // Upload a file to the provider...
        $file = Files::put(
            Document::fromString('This is test content for the vector store.', 'text/plain')->as('test.txt'),
            provider: $this->provider,
        );

        // Add the file to the store...
        $documentId = $store->add($file);

        $this->assertNotEmpty($documentId);

        // Refresh the store to see updated file counts...
        $refreshed = $store->refresh();

        $this->assertGreaterThanOrEqual(0, $refreshed->fileCounts->completed + $refreshed->fileCounts->pending);

        // Remove the file from the store....
        $removed = $store->remove($documentId, deleteFile: true);

        $this->assertTrue($removed);

        // Clean up...
        $store->delete();
    }

    public function test_can_actually_prompt_an_agent_with_file_search_data()
    {
        // OpenAI: vs_695d788d9afc8191aa87e0ef81bacbda, file-7r66Gzib7ooyhJcxKDyq2q
        // Gemini: fileSearchStores/laravel-ai-sdk-test-store-ur5230zq9t31, kxv9av2adm6m-wys58b8hnirl
        $storeId = $this->provider === 'openai'
            ? 'vs_695d788d9afc8191aa87e0ef81bacbda'
            : 'fileSearchStores/laravel-ai-sdk-test-store-ur5230zq9t31';

        // $store = Stores::get($storeId, provider: $this->provider);
        // $document = $store->add(Document::fromPath(__DIR__.'/../../tmp/laravel.pdf'), metadata: ['company' => 'laravel']);

        $response = agent(
            instructions: 'You will use the file search tool available to you to answer questions about the documents you have access to.',
            tools: [
                new FileSearch([$storeId]),
            ],
        )->prompt('Is Valkey mentioned in the sixth month roadmap? Can you quote the section where it is mentioned?', provider: $this->provider);

        $this->assertTrue(str_contains((string) $response, 'Yes'));
        $this->assertTrue(str_contains((string) $response, 'Valkey'));
    }

    public function test_can_actually_prompt_an_agent_with_filtered_search_data()
    {
        // OpenAI: vs_695d788d9afc8191aa87e0ef81bacbda, file-7r66Gzib7ooyhJcxKDyq2q
        // Gemini: fileSearchStores/laravel-ai-sdk-test-store-ur5230zq9t31, kxv9av2adm6m-wys58b8hnirl
        $storeId = $this->provider === 'openai'
            ? 'vs_695d788d9afc8191aa87e0ef81bacbda'
            : 'fileSearchStores/laravel-ai-sdk-test-store-ur5230zq9t31';

        // Tailwind...
        $response = agent(
            instructions: 'You will use the file search tool available to you to answer questions about the documents you have access to.',
            tools: [
                new FileSearch([$storeId], where: ['company' => 'tailwind']),
            ],
        )->prompt('Do you see any mention of Valkey in the documents you have access to?', provider: $this->provider);

        $this->assertTrue(str_contains(strtolower((string) $response), 'no'));

        // Laravel...
        $response = agent(
            instructions: 'You will use the file search tool available to you to answer questions about the documents you have access to.',
            tools: [
                new FileSearch(
                    [$storeId],
                    where: fn ($query) => $query->where('company', 'laravel')
                ),
            ],
        )->prompt('Do you see any mention of Valkey in the documents you have access to?', provider: $this->provider);

        $this->assertTrue(str_contains((string) $response, 'Yes'));
    }
}
