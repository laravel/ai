<?php

use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;

test('mime type is null when the disk cannot detect it', function (string $class): void {
    Storage::fake('files');

    expect((new $class('missing.bin', 'files'))->mimeType())->toBeNull();
})->with([StoredDocument::class, StoredAudio::class]);
