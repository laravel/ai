<?php

namespace Tests\Feature;

use DateInterval;
use Illuminate\Support\Collection;
use Laravel\Ai\Store;
use Laravel\Ai\Stores;
use RuntimeException;
use Tests\TestCase;

use function Illuminate\Support\days;

class StoreFakeTest extends TestCase
{
    public function test_stores_can_be_faked(): void
    {
        Stores::fake([
            'first-store',
            fn ($storeId) => "store-{$storeId}",
            'Custom Store',
        ]);

        $response = Stores::get('vs_1');
        $this->assertEquals('vs_1', $response->id);
        $this->assertEquals('first-store', $response->name);

        $response = Stores::get('vs_2');
        $this->assertEquals('vs_2', $response->id);
        $this->assertEquals('store-vs_2', $response->name);

        $response = Stores::get('vs_3');
        $this->assertEquals('vs_3', $response->id);
        $this->assertEquals('Custom Store', $response->name);
    }

    public function test_stores_can_be_faked_with_no_predefined_responses(): void
    {
        Stores::fake();

        $response = Stores::get('vs_1');

        $this->assertEquals('vs_1', $response->id);
        $this->assertEquals('fake-store', $response->name);
    }

    public function test_stores_can_be_faked_with_a_closure(): void
    {
        Stores::fake(fn ($storeId) => "name-for-{$storeId}");

        $response = Stores::get('vs_1');

        $this->assertEquals('vs_1', $response->id);
        $this->assertEquals('name-for-vs_1', $response->name);
    }

    public function test_stores_can_prevent_stray_operations(): void
    {
        $this->expectException(RuntimeException::class);

        Stores::fake()->preventStrayOperations();

        Stores::get('vs_1');
    }

    public function test_can_assert_store_was_created_by_name(): void
    {
        Stores::fake();

        Stores::create('My Vector Store');

        Stores::assertCreated('My Vector Store');
        Stores::assertNotCreated('Other Store');
    }

    public function test_can_assert_store_was_created_with_closure(): void
    {
        Stores::fake();

        Stores::create(
            name: 'My Vector Store',
            description: 'A test store',
            expiresWhenIdleFor: days(7),
        );

        Stores::assertCreated(fn (string $name) => $name === 'My Vector Store');
        Stores::assertCreated(fn (string $name, ?string $description) => $description === 'A test store');

        Stores::assertCreated(fn (
            string $name,
            ?string $description,
            Collection $fileIds,
            ?DateInterval $expiresWhenIdleFor
        ) => $expiresWhenIdleFor !== null);

        Stores::assertNotCreated(fn (string $name) => $name === 'Other Store');
    }

    public function test_can_assert_no_stores_were_created(): void
    {
        Stores::fake();

        Stores::assertNothingCreated();
    }

    public function test_can_assert_store_was_deleted(): void
    {
        Stores::fake();

        Stores::delete('vs_123');

        Stores::assertDeleted('vs_123');
        Stores::assertDeleted(fn (string $id) => $id === 'vs_123');
        Stores::assertNotDeleted('vs_456');
        Stores::assertNotDeleted(fn (string $id) => $id === 'vs_456');
    }

    public function test_can_assert_no_stores_were_deleted(): void
    {
        Stores::fake();

        Stores::assertNothingDeleted();
    }
}
