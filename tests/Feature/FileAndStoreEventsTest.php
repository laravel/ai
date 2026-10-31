<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\Events\AddingFileToStore;
use Laravel\Ai\Events\AddingFileToStoreFailed;
use Laravel\Ai\Events\CreatingStore;
use Laravel\Ai\Events\CreatingStoreFailed;
use Laravel\Ai\Events\RemovingFileFromStore;
use Laravel\Ai\Events\RemovingFileFromStoreFailed;
use Laravel\Ai\Events\StoringFile;
use Laravel\Ai\Events\StoringFileFailed;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\ProviderDocument;

beforeEach(function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('file and store failures dispatch an event mirroring the starting event and rethrow', function (Closure $invoke, string $startingEvent, string $failureEvent): void {
    Event::fake();

    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['message' => 'Server error.']], 500),
    ]);

    $caught = null;

    try {
        $invoke(app(AiManager::class)->instance('openai'));
    } catch (Throwable $e) {
        $caught = $e;
    }

    Event::assertDispatchedTimes($startingEvent, 1);
    Event::assertDispatchedTimes($failureEvent, 1);

    $started = Event::dispatched($startingEvent)->first()[0];
    $failed = Event::dispatched($failureEvent)->first()[0];

    expect($caught)->not->toBeNull()
        ->and($failed->exception)->toBe($caught)
        ->and(Arr::except(get_object_vars($failed), 'exception'))->toBe(get_object_vars($started));
})->with([
    'storing a file' => [
        fn ($provider) => $provider->putFile(Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')),
        StoringFile::class,
        StoringFileFailed::class,
    ],
    'creating a store' => [
        fn ($provider) => $provider->createStore('Knowledge Base', 'Product docs'),
        CreatingStore::class,
        CreatingStoreFailed::class,
    ],
    'adding a file to a store' => [
        fn ($provider) => $provider->addFileToStore('vs-123', new ProviderDocument('file-abc123')),
        AddingFileToStore::class,
        AddingFileToStoreFailed::class,
    ],
    'removing a file from a store' => [
        fn ($provider) => $provider->removeFileFromStore('vs-123', 'file-abc123'),
        RemovingFileFromStore::class,
        RemovingFileFromStoreFailed::class,
    ],
]);
