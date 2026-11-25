<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\ProviderDocument;
use Laravel\Ai\Responses\Data\StoreDocument;
use Laravel\Ai\Stores;

use function Illuminate\Support\days;

describe('store operations', function (): void {
    test('stores can be faked', function (): void {
        Stores::fake([
            'first-store',
            fn ($storeId): string => "store-{$storeId}",
            'Custom Store',
        ]);

        $response = Stores::get('vs_1');
        expect($response)->id->toEqual('vs_1')->name->toEqual('first-store');

        $response = Stores::get('vs_2');
        expect($response)->id->toEqual('vs_2')->name->toEqual('store-vs_2');

        $response = Stores::get('vs_3');
        expect($response)->id->toEqual('vs_3')->name->toEqual('Custom Store');
    });

    test('stores can be faked with no predefined responses', function (): void {
        Stores::fake();

        $response = Stores::get('vs_1');

        expect($response)->id->toEqual('vs_1')->name->toEqual('fake-store');
    });

    test('stores can be faked with a closure', function (): void {
        Stores::fake(fn ($storeId): string => "name-for-{$storeId}");

        $response = Stores::get('vs_1');

        expect($response)->id->toEqual('vs_1')->name->toEqual('name-for-vs_1');
    });

    test('stores can prevent stray operations', function (): void {
        Stores::fake()->preventStrayOperations();

        Stores::get('vs_1');
    })->throws(RuntimeException::class);
});

describe('store assertions', function (): void {
    test('can assert store was created by name', function (): void {
        Stores::fake();

        Stores::create('My Vector Store');

        Stores::assertCreated('My Vector Store');
        Stores::assertNotCreated('Other Store');
    });

    test('can assert store was created with closure', function (): void {
        Stores::fake();

        Stores::create(
            name: 'My Vector Store',
            description: 'A test store',
            expiresWhenIdleFor: days(7),
        );

        Stores::assertCreated(fn (string $name): bool => $name === 'My Vector Store');
        Stores::assertCreated(fn (string $name, ?string $description): bool => $description === 'A test store');

        Stores::assertCreated(fn (
            string $name,
            ?string $description,
            Collection $fileIds,
            ?DateInterval $expiresWhenIdleFor
        ): bool => $expiresWhenIdleFor instanceof DateInterval);

        Stores::assertNotCreated(fn (string $name): bool => $name === 'Other Store');
    });

    test('can assert no stores were created', function (): void {
        Stores::fake();

        Stores::assertNothingCreated();
    });

    test('can assert store was deleted', function (): void {
        Stores::fake();

        Stores::delete('vs_123');

        Stores::assertDeleted('vs_123');
        Stores::assertDeleted(fn (string $id): bool => $id === 'vs_123');

        Stores::assertNotDeleted('vs_456');
        Stores::assertNotDeleted(fn (string $id): bool => $id === 'vs_456');
    });

    test('can assert no stores were deleted', function (): void {
        Stores::fake();

        Stores::assertNothingDeleted();
    });
});

describe('file operations', function (): void {
    test('can add file to store with provider id', function (): void {
        Stores::fake();

        $store = Stores::create('My Store');

        $searchable = $store->add(new ProviderDocument('file_123'));

        expect($searchable)->id->toEqual('file_123')
            ->and($searchable->fileId())->toEqual('file_123');
    });

    test('can remove file from store with provider id', function (): void {
        Stores::fake();

        $result = Stores::create('My Store')->remove(new ProviderDocument('file_123'));

        expect($result)->toBeTrue();
    });

    test('can remove file from store with string id', function (): void {
        Stores::fake();

        $result = Stores::create('My Store')->remove('file_123');

        expect($result)->toBeTrue();
    });

    test('can add storable file to store', function (): void {
        Stores::fake();

        $response = Stores::create('My Store')
            ->add(Document::fromString('Hello, world!', 'text/plain'));

        expect($response)->not->toBeEmpty();

        Files::assertStored(
            fn (StorableFile $file): bool => $file->content() === 'Hello, world!'
        );
    });

    test('can add an uploaded file to store from its path', function (): void {
        Stores::fake();

        Stores::create('My Store')
            ->add(new UploadedFile(__DIR__.'/../Fixtures/report.txt', 'report.txt', 'text/plain'));

        Files::assertStored(fn (StorableFile $file): bool => $file instanceof LocalDocument);
        Files::assertStored(fn (StorableFile $file): bool => $file->name() === 'report.txt');
        Files::assertStored(fn (StorableFile $file): bool => $file->mimeType() === 'text/plain');
        Files::assertStored(fn (StorableFile $file): bool => trim((string) $file) === 'I am an expense report.');
    });

    test('an added fake upload can still be read after the upload object is gone', function (): void {
        Stores::fake();

        Stores::create('My Store')
            ->add(UploadedFile::fake()->createWithContent('report.txt', 'I am an expense report.'));

        Files::assertStored(fn (StorableFile $file): bool => trim((string) $file) === 'I am an expense report.');
    });

    test('cannot add an uploaded file that failed to upload to store', function (): void {
        Stores::fake();

        Stores::create('My Store')
            ->add(new UploadedFile('', 'report.txt', 'text/plain', UPLOAD_ERR_NO_TMP_DIR));
    })->throws(InvalidArgumentException::class);
});

describe('file assertions', function (): void {
    test('can assert file added to store', function (): void {
        Stores::fake();

        $store = Stores::create('My Store');
        $file = new ProviderDocument(Files::fakeId('test.txt'));

        $store->add($file);

        // Using closure receives the original file...
        $store->assertAdded(fn ($f): bool => $f instanceof ProviderDocument && $f->id() === $file->id());

        // Using exact IDs...
        $store->assertAdded($file->id());

        // Using friendly names (automatically converted to fake IDs)...
        $store->assertAdded('test.txt');
    });

    test('can assert file added to store with storable file', function (): void {
        Stores::fake();

        $store = Stores::create('My Store');

        $store->add(Document::fromString('Hello, world!', 'text/plain')->as('hello.txt'));

        // Using closure receives the original StorableFile...
        $store->assertAdded(fn (StorableFile $file): bool => $file->name() === 'hello.txt');
        $store->assertAdded(fn (StorableFile $file): bool => $file->content() === 'Hello, world!');
    });

    test('can assert file not added to store', function (): void {
        Stores::fake();

        $store = Stores::create('My Store');
        $file = new ProviderDocument('file_123');

        $store->add($file);

        $store->assertNotAdded(fn ($f): bool => $f instanceof ProviderDocument && $f->id() === 'file_456');
    });

    test('can assert file removed from store', function (): void {
        Stores::fake();

        $store = Stores::create('My Store');
        $fileId = Files::fakeId('test.txt');

        $store->remove($fileId);

        // Using closure...
        $store->assertRemoved(fn ($fId): bool => $fId === $fileId);

        // Using exact IDs...
        $store->assertRemoved($fileId);

        // Using friendly names (automatically converted to fake IDs)...
        $store->assertRemoved('test.txt');
    });

    test('can assert file not removed from store', function (): void {
        Stores::fake();

        $store = Stores::create('My Store');

        $store->remove('file_123');

        $store->assertNotRemoved(fn ($fileId): bool => $fileId === 'file_456');
    });
});

describe('listing files', function (): void {
    test('files added to a fake store are listed', function (): void {
        Stores::fake();

        $store = Stores::create('My Store');

        $store->add('file_1', ['company' => 'laravel']);
        $store->add('file_2');

        $documents = $store->files()->all();

        expect($documents)->toHaveCount(2)
            ->and($documents[0])->toBeInstanceOf(StoreDocument::class)
            ->and($documents[0]->id)->toBe('file_1')
            ->and($documents[0]->fileId)->toBe('file_1')
            ->and($documents[0]->status)->toBe('completed')
            ->and($documents[0]->metadata)->toBe(['company' => 'laravel'])
            ->and($documents[1]->id)->toBe('file_2');
    });

    test('files removed from a fake store are no longer listed', function (): void {
        Stores::fake();

        $store = Stores::create('My Store');

        $store->add('file_1');
        $store->add('file_2');
        $store->remove('file_1');

        expect($store->files()->pluck('id')->all())->toBe(['file_2']);
    });

    test('files are listed per store', function (): void {
        Stores::fake();

        $first = Stores::create('First Store');
        $second = Stores::create('Second Store');

        $first->add('file_1');
        $second->add('file_2');

        expect($first->files()->pluck('id')->all())->toBe(['file_1'])
            ->and($second->files()->pluck('id')->all())->toBe(['file_2']);
    });

    test('a fake store can be seeded with documents', function (): void {
        Stores::fake()->withDocuments('vs_1', [
            'file_1',
            new StoreDocument('doc_2', status: 'pending'),
        ]);

        $documents = Stores::get('vs_1')->files()->all();

        expect($documents)->toHaveCount(2)
            ->and($documents[0]->id)->toBe('file_1')
            ->and($documents[0]->completed())->toBeTrue()
            ->and($documents[1]->id)->toBe('doc_2')
            ->and($documents[1]->fileId)->toBeNull()
            ->and($documents[1]->completed())->toBeFalse();
    });

    test('an unknown fake store has no files', function (): void {
        Stores::fake();

        expect(Stores::get('vs_unknown')->files()->all())->toBe([]);
    });
});
