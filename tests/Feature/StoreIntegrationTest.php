<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\CreatingStore;
use Laravel\Ai\Events\StoreCreated;
use Laravel\Ai\Events\StoreDeleted;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Stores;

use function Illuminate\Support\days;
use function Laravel\Ai\agent;

beforeEach(function () {
    $this->provider = 'openai';
});

test('can create get and delete store', function () {
    Event::fake();

    $created = Stores::create('Test Store', provider: $this->provider);

    expect($created->id)->not->toBeEmpty();

    Event::assertDispatched(CreatingStore::class);
    Event::assertDispatched(StoreCreated::class);

    $retrieved = Stores::get($created->id, provider: $this->provider);

    expect($retrieved->id)->toEqual($created->id)
        ->and($retrieved->name)->toEqual('Test Store')
        ->and($retrieved->fileCounts->completed)->toEqual(0)
        ->and($retrieved->ready)->toBeBool();

    $deleted = Stores::delete($created->id, provider: $this->provider);

    expect($deleted)->toBeTrue();

    Event::assertDispatched(StoreDeleted::class);
});

test('can create store with expiration', function () {
    $created = Stores::create(
        name: 'Expiring Store',
        description: 'A store that expires after 7 days of inactivity.',
        expiresWhenIdleFor: days(7),
        provider: $this->provider,
    );

    expect($created->id)->not->toBeEmpty();

    Stores::delete($created->id, provider: $this->provider);
});

test('can add and remove file from store', function () {
    // Create a store...
    $store = Stores::create('File Test Store', provider: $this->provider);

    // Upload a file to the provider...
    $file = Files::put(
        Document::fromString('This is test content for the vector store.', 'text/plain')->as('test.txt'),
        provider: $this->provider,
    );

    // Add the file to the store...
    $documentId = $store->add($file);

    expect($documentId)->not->toBeEmpty();

    // Refresh the store to see updated file counts...
    $refreshed = $store->refresh();

    expect($refreshed->fileCounts->completed + $refreshed->fileCounts->pending)->toBeGreaterThanOrEqual(0);

    // Remove the file from the store....
    $removed = $store->remove($documentId, deleteFile: true);

    expect($removed)->toBeTrue();

    // Clean up...
    $store->delete();
});

test('can actually prompt an agent with file search data', function () {
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

    expect(str_contains((string) $response, 'Yes'))->toBeTrue()
        ->and(str_contains((string) $response, 'Valkey'))->toBeTrue();
});

test('can actually prompt an agent with filtered search data', function () {
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

    expect(str_contains(strtolower((string) $response), 'no'))->toBeTrue();

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

    expect(str_contains((string) $response, 'Yes'))->toBeTrue();
});
