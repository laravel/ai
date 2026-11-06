<?php

use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Gateway\Concerns\PreparesStorableFiles;

function storableFileGateway(): object
{
    return new class
    {
        use PreparesStorableFiles;

        public function upload(StorableFile $file, Closure $callback): mixed
        {
            return $this->withPreparedStorableFile($file, $callback);
        }
    };
}

function recordingLocalDocument(): LocalDocument
{
    return new class(__DIR__.'/../../../Fixtures/document.txt', 'text/plain') extends LocalDocument
    {
        public array $streams = [];

        public function stream(): mixed
        {
            return $this->streams[] = parent::stream();
        }
    };
}

test('streamable files are passed as a stream that is closed after the upload', function (): void {
    $file = recordingLocalDocument();

    $result = storableFileGateway()->upload($file, fn ($content, $mime, $name) => [stream_get_contents($content), $mime, $name]);

    expect($result)->toBe(["I am a local document.\n", 'text/plain', 'document.txt'])
        ->and(is_resource($file->streams[0]))->toBeFalse();
});

test('the stream is closed when the upload throws', function (): void {
    $file = recordingLocalDocument();

    expect(fn () => storableFileGateway()->upload($file, fn () => throw new RuntimeException('Upload aborted.')))
        ->toThrow(RuntimeException::class, 'Upload aborted.')
        ->and(is_resource($file->streams[0]))->toBeFalse();
});

test('files without a stream are passed as their buffered content with fallback metadata', function (): void {
    $result = storableFileGateway()->upload(
        Document::fromString('Hello, World!'),
        fn ($content, $mime, $name) => [$content, $mime, $name],
    );

    expect($result)->toBe(['Hello, World!', 'application/octet-stream', 'file']);
});
