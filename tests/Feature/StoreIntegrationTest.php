<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\CreatingStore;
use Laravel\Ai\Events\StoreCreated;
use Laravel\Ai\Events\StoreDeleted;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Stores;
use Tests\TestCase;

use function Illuminate\Support\days;

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
}
