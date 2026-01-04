<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\CreatingStore;
use Laravel\Ai\Events\StoreCreated;
use Laravel\Ai\Events\StoreDeleted;
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
}
