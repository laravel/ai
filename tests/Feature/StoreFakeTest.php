<?php

namespace Tests\Feature;

use DateInterval;
use Illuminate\Support\Collection;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\ProviderDocument;
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

    public function test_can_add_file_to_store_with_provider_id(): void
    {
        Stores::fake();

        $store = Stores::create('My Store');

        $searchable = $store->add(new ProviderDocument('file_123'));

        $this->assertEquals('file_123', $searchable->id);
        $this->assertEquals('file_123', $searchable->fileId());
    }

    public function test_can_remove_file_from_store_with_provider_id(): void
    {
        Stores::fake();

        $result = Stores::create('My Store')->remove(new ProviderDocument('file_123'));

        $this->assertTrue($result);
    }

    public function test_can_remove_file_from_store_with_string_id(): void
    {
        Stores::fake();

        $result = Stores::create('My Store')->remove('file_123');

        $this->assertTrue($result);
    }

    public function test_can_add_storable_file_to_store(): void
    {
        Stores::fake();

        $response = Stores::create('My Store')
            ->add(Document::fromString('Hello, world!', 'text/plain'));

        $this->assertNotEmpty($response);

        Files::assertStored(
            fn (StorableFile $file) => $file->content() === 'Hello, world!'
        );
    }

    public function test_can_assert_file_added_to_store(): void
    {
        Stores::fake();

        $store = Stores::create('My Store');
        $file = new ProviderDocument(Files::fakeId('test.txt'));

        $store->add($file);

        // Using closure...
        $store->assertAdded(fn ($fileId) => $fileId === $file->id());

        // Using exact IDs...
        $store->assertAdded($file->id());

        // Using friendly names (automatically converted to fake IDs)...
        $store->assertAdded('test.txt');
    }

    public function test_can_assert_file_not_added_to_store(): void
    {
        Stores::fake();

        $store = Stores::create('My Store');
        $file = new ProviderDocument('file_123');

        $store->add($file);

        $store->assertNotAdded(fn ($fileId) => $fileId === 'file_456');
    }

    public function test_can_assert_file_removed_from_store(): void
    {
        Stores::fake();

        $store = Stores::create('My Store');
        $fileId = Files::fakeId('test.txt');

        $store->remove($fileId);

        // Using closure...
        $store->assertRemoved(fn ($fId) => $fId === $fileId);

        // Using exact IDs...
        $store->assertRemoved($fileId);

        // Using friendly names (automatically converted to fake IDs)...
        $store->assertRemoved('test.txt');
    }

    public function test_can_assert_file_not_removed_from_store(): void
    {
        Stores::fake();

        $store = Stores::create('My Store');

        $store->remove('file_123');

        $store->assertNotRemoved(fn ($fileId) => $fileId === 'file_456');
    }
}
