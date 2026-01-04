<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\CreatingStore;
use Laravel\Ai\Events\StoreCreated;
use Laravel\Ai\Events\StoreDeleted;
use Laravel\Ai\Stores;
use Tests\TestCase;

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

        $deleted = Stores::delete($created->id, provider: $this->provider);

        $this->assertTrue($deleted);

        Event::assertDispatched(StoreDeleted::class);
    }
}
