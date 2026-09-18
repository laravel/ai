<?php

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Files\LocalDocument;

test('a document created from an upload can be serialized', function (): void {
    $document = LocalDocument::fromUploadedFile(
        UploadedFile::fake()->createWithContent('report.txt', 'I am an expense report.')
    )->withProviderOptions(['purpose' => 'assistants']);

    $unserialized = unserialize(serialize($document));

    expect($unserialized->path)->toBe($document->path)
        ->and($unserialized->name())->toBe('report.txt')
        ->and($unserialized->mimeType())->toBe($document->mimeType())
        ->and($unserialized->providerOptions('openai'))->toBe(['purpose' => 'assistants']);
});
