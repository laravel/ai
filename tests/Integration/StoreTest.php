<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\CreatingStore;
use Laravel\Ai\Events\StoreCreated;
use Laravel\Ai\Events\StoreDeleted;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Store;
use Laravel\Ai\Stores;

use function Illuminate\Support\days;
use function Laravel\Ai\agent;

function createFileSearchStore(string $provider): array
{
    $store = Stores::create(
        'Laravel AI SDK Integration Test Store',
        provider: $provider,
    );

    $fileIds = [];

    $fileIds[] = $store->add(
        Document::fromPath(__DIR__.'/../Fixtures/laravel-roadmap.txt'),
        metadata: ['company' => 'laravel'],
    )->fileId;

    $fileIds[] = $store->add(
        Document::fromPath(__DIR__.'/../Fixtures/tailwind-roadmap.txt'),
        metadata: ['company' => 'tailwind'],
    )->fileId;

    $store = retry(60, function () use ($store): Store {
        $refreshed = $store->refresh();

        if ($refreshed->fileCounts->completed < 2) {
            throw new RuntimeException("Store {$refreshed->id} has only {$refreshed->fileCounts->completed} of 2 files indexed.");
        }

        return $refreshed;
    }, 2000);

    return [$store, $fileIds];
}

test('can create get and delete store', function (string $provider, string $apiKey): void {
    requiresApiKey($apiKey);

    Event::fake();

    $created = Stores::create('Test Store', provider: $provider);

    expect($created->id)->not->toBeEmpty();

    Event::assertDispatched(CreatingStore::class);
    Event::assertDispatched(StoreCreated::class);

    $retrieved = Stores::get($created->id, provider: $provider);

    expect($retrieved->id)->toEqual($created->id)
        ->and($retrieved->name)->toEqual('Test Store')
        ->and($retrieved->fileCounts->completed)->toEqual(0)
        ->and($retrieved->ready)->toBeBool();

    $deleted = Stores::delete($created->id, provider: $provider);

    expect($deleted)->toBeTrue();

    Event::assertDispatched(StoreDeleted::class);
})->with('store-providers');

test('can create store with expiration', function (string $provider, string $apiKey): void {
    requiresApiKey($apiKey);

    $created = Stores::create(
        name: 'Expiring Store',
        description: 'A store that expires after 7 days of inactivity.',
        expiresWhenIdleFor: days(7),
        provider: $provider,
    );

    expect($created->id)->not->toBeEmpty();

    Stores::delete($created->id, provider: $provider);
})->with('store-providers');

test('can add and remove file from store', function (string $provider, string $apiKey): void {
    requiresApiKey($apiKey);

    // Create a store...
    $store = Stores::create('File Test Store', provider: $provider);

    // Upload a file to the provider...
    $file = Files::put(
        Document::fromString('This is test content for the vector store.', 'text/plain')->as('test.txt'),
        provider: $provider,
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
})->with('store-providers');

describe('file search', function (): void {
    afterEach(function (): void {
        if (property_exists($this, 'fileSearchStore') && $this->fileSearchStore !== null) {
            $this->fileSearchStore->delete();
        }

        foreach ($this->fileSearchFileIds ?? [] as $fileId) {
            Files::delete($fileId, provider: $this->provider);
        }
    });

    test('can actually prompt an agent with file search data', function (string $provider, string $apiKey): void {
        requiresApiKey($apiKey);

        $this->provider = $provider;
        [$this->fileSearchStore, $this->fileSearchFileIds] = createFileSearchStore($provider);

        $response = agent(
            instructions: 'You will use the file search tool available to you to answer questions about the documents you have access to.',
            tools: [
                new FileSearch([$this->fileSearchStore->id]),
            ],
        )->prompt('Is Valkey mentioned in the sixth month roadmap? Can you quote the section where it is mentioned?', provider: $provider);

        expect((string) $response)->toContain('Yes')->toContain('Valkey');
    })->with('file-search-providers');

    test('can actually prompt an agent with filtered search data', function (): void {
        requiresApiKey('OPENAI_API_KEY');

        $this->provider = 'openai';
        [$this->fileSearchStore, $this->fileSearchFileIds] = createFileSearchStore('openai');

        $instructions = 'Answer strictly based on the documents returned by the file search tool. '
            .'Do not use prior knowledge. Respond with exactly one word: "Yes" or "No".';
        $prompt = 'Do any of the documents you have access to mention Valkey?';

        $response = agent(
            instructions: $instructions,
            tools: [
                new FileSearch([$this->fileSearchStore->id], where: ['company' => 'tailwind']),
            ],
        )->prompt($prompt, provider: 'openai');

        expect(trim((string) $response))->toStartWith('No');

        $response = agent(
            instructions: $instructions,
            tools: [
                new FileSearch(
                    [$this->fileSearchStore->id],
                    where: fn ($query) => $query->where('company', 'laravel')
                ),
            ],
        )->prompt($prompt, provider: 'openai');

        expect(trim((string) $response))->toStartWith('Yes');
    });
});
