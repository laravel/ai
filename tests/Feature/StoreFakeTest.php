<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\ProviderDocument;
use Laravel\Ai\Stores;

use function Illuminate\Support\days;

test('stores can be faked', function () {
    Stores::fake([
        'first-store',
        fn ($storeId) => "store-{$storeId}",
        'Custom Store',
    ]);

    $response = Stores::get('vs_1');
    expect($response->id)->toEqual('vs_1');
    expect($response->name)->toEqual('first-store');

    $response = Stores::get('vs_2');
    expect($response->id)->toEqual('vs_2');
    expect($response->name)->toEqual('store-vs_2');

    $response = Stores::get('vs_3');
    expect($response->id)->toEqual('vs_3');
    expect($response->name)->toEqual('Custom Store');
});

test('stores can be faked with no predefined responses', function () {
    Stores::fake();

    $response = Stores::get('vs_1');

    expect($response->id)->toEqual('vs_1');
    expect($response->name)->toEqual('fake-store');
});

test('stores can be faked with a closure', function () {
    Stores::fake(fn ($storeId) => "name-for-{$storeId}");

    $response = Stores::get('vs_1');

    expect($response->id)->toEqual('vs_1');
    expect($response->name)->toEqual('name-for-vs_1');
});

test('stores can prevent stray operations', function () {
    Stores::fake()->preventStrayOperations();

    Stores::get('vs_1');
})->throws(RuntimeException::class);

test('can assert store was created by name', function () {
    Stores::fake();

    Stores::create('My Vector Store');

    Stores::assertCreated('My Vector Store');
    Stores::assertNotCreated('Other Store');
});

test('can assert store was created with closure', function () {
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
});

test('can assert no stores were created', function () {
    Stores::fake();

    Stores::assertNothingCreated();
});

test('can assert store was deleted', function () {
    Stores::fake();

    Stores::delete('vs_123');

    Stores::assertDeleted('vs_123');
    Stores::assertDeleted(fn (string $id) => $id === 'vs_123');

    Stores::assertNotDeleted('vs_456');
    Stores::assertNotDeleted(fn (string $id) => $id === 'vs_456');
});

test('can assert no stores were deleted', function () {
    Stores::fake();

    Stores::assertNothingDeleted();
});

test('can add file to store with provider id', function () {
    Stores::fake();

    $store = Stores::create('My Store');

    $searchable = $store->add(new ProviderDocument('file_123'));

    expect($searchable->id)->toEqual('file_123');
    expect($searchable->fileId())->toEqual('file_123');
});

test('can remove file from store with provider id', function () {
    Stores::fake();

    $result = Stores::create('My Store')->remove(new ProviderDocument('file_123'));

    expect($result)->toBeTrue();
});

test('can remove file from store with string id', function () {
    Stores::fake();

    $result = Stores::create('My Store')->remove('file_123');

    expect($result)->toBeTrue();
});

test('can add storable file to store', function () {
    Stores::fake();

    $response = Stores::create('My Store')
        ->add(Document::fromString('Hello, world!', 'text/plain'));

    expect($response)->not->toBeEmpty();

    Files::assertStored(
        fn (StorableFile $file) => $file->content() === 'Hello, world!'
    );
});

test('can assert file added to store', function () {
    Stores::fake();

    $store = Stores::create('My Store');
    $file = new ProviderDocument(Files::fakeId('test.txt'));

    $store->add($file);

    // Using closure receives the original file...
    $store->assertAdded(fn ($f) => $f instanceof ProviderDocument && $f->id() === $file->id());

    // Using exact IDs...
    $store->assertAdded($file->id());

    // Using friendly names (automatically converted to fake IDs)...
    $store->assertAdded('test.txt');
});

test('can assert file added to store with storable file', function () {
    Stores::fake();

    $store = Stores::create('My Store');

    $store->add(Document::fromString('Hello, world!', 'text/plain')->as('hello.txt'));

    // Using closure receives the original StorableFile...
    $store->assertAdded(fn (StorableFile $file) => $file->name() === 'hello.txt');
    $store->assertAdded(fn (StorableFile $file) => $file->content() === 'Hello, world!');
});

test('can assert file not added to store', function () {
    Stores::fake();

    $store = Stores::create('My Store');
    $file = new ProviderDocument('file_123');

    $store->add($file);

    $store->assertNotAdded(fn ($f) => $f instanceof ProviderDocument && $f->id() === 'file_456');
});

test('can assert file removed from store', function () {
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
});

test('can assert file not removed from store', function () {
    Stores::fake();

    $store = Stores::create('My Store');

    $store->remove('file_123');

    $store->assertNotRemoved(fn ($fileId) => $fileId === 'file_456');
});
