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
    requiresApiKey('OPENAI_API_KEY');

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

describe('file search', function () {
    beforeEach(function () {
        requiresApiKey('OPENAI_API_KEY');

        $this->fileSearchStore = Stores::create(
            'Laravel AI SDK Integration Test Store',
            provider: $this->provider,
        );

        $this->fileSearchFileIds = [];

        $this->fileSearchFileIds[] = $this->fileSearchStore->add(
            Document::fromPath(__DIR__.'/../Fixtures/laravel-roadmap.txt'),
            metadata: ['company' => 'laravel'],
        )->fileId;

        $this->fileSearchFileIds[] = $this->fileSearchStore->add(
            Document::fromPath(__DIR__.'/../Fixtures/tailwind-roadmap.txt'),
            metadata: ['company' => 'tailwind'],
        )->fileId;

        $this->fileSearchStore = retry(60, function () {
            $refreshed = $this->fileSearchStore->refresh();

            if ($refreshed->fileCounts->completed < 2) {
                throw new RuntimeException("Store {$refreshed->id} has only {$refreshed->fileCounts->completed} of 2 files indexed.");
            }

            return $refreshed;
        }, 2000);
    });

    afterEach(function () {
        if (isset($this->fileSearchStore)) {
            $this->fileSearchStore->delete();
        }
    });

    test('can actually prompt an agent with file search data', function () {
        $response = agent(
            instructions: 'You will use the file search tool available to you to answer questions about the documents you have access to.',
            tools: [
                new FileSearch([$this->fileSearchStore->id]),
            ],
        )->prompt('Is Valkey mentioned in the sixth month roadmap? Can you quote the section where it is mentioned?', provider: $this->provider);

        expect(str_contains((string) $response, 'Yes'))->toBeTrue()
            ->and(str_contains((string) $response, 'Valkey'))->toBeTrue();
    });

    test('can actually prompt an agent with filtered search data', function () {
        $instructions = 'Answer strictly based on the documents returned by the file search tool. '
            .'Do not use prior knowledge. Respond with exactly one word: "Yes" or "No".';
        $prompt = 'Do any of the documents you have access to mention Valkey?';

        $response = agent(
            instructions: $instructions,
            tools: [
                new FileSearch([$this->fileSearchStore->id], where: ['company' => 'tailwind']),
            ],
        )->prompt($prompt, provider: $this->provider);

        expect(trim((string) $response))->toStartWith('No');

        $response = agent(
            instructions: $instructions,
            tools: [
                new FileSearch(
                    [$this->fileSearchStore->id],
                    where: fn ($query) => $query->where('company', 'laravel')
                ),
            ],
        )->prompt($prompt, provider: $this->provider);

        expect(trim((string) $response))->toStartWith('Yes');
    });
});
