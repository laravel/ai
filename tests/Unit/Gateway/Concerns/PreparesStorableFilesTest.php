<?php

use Laravel\Ai\Contracts\Files\HasStream;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Gateway\Concerns\PreparesStorableFiles;

test('upload preparation closes the file stream on success and failure', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'ai-stream-');
    file_put_contents($path, 'stream bytes');

    $file = new class($path) implements HasStream, StorableFile
    {
        public array $streams = [];

        public function __construct(public string $path) {}

        public function stream(): mixed
        {
            return $this->streams[] = fopen($this->path, 'rb');
        }

        public function content(): string
        {
            throw new RuntimeException('Streaming files must not be buffered.');
        }

        public function mimeType(): ?string
        {
            return 'text/plain';
        }

        public function name(): ?string
        {
            return 'sample.txt';
        }

        public function __toString(): string
        {
            return 'stream bytes';
        }
    };

    $gateway = new class
    {
        use PreparesStorableFiles;

        public function upload(StorableFile $file, Closure $callback): mixed
        {
            return $this->withPreparedStorableFile($file, $callback);
        }
    };

    try {
        expect($gateway->upload($file, function ($content, $mime, $name): string {
            expect(is_resource($content))->toBeTrue();
            expect(stream_get_contents($content))->toBe('stream bytes');

            return "{$mime}:{$name}";
        }))->toBe('text/plain:sample.txt');

        expect(is_resource($file->streams[0]))->toBeFalse();

        expect(fn () => $gateway->upload($file, function ($content): void {
            expect(is_resource($content))->toBeTrue();

            throw new RuntimeException('Upload aborted.');
        }))->toThrow(RuntimeException::class, 'Upload aborted.');

        expect(is_resource($file->streams[1]))->toBeFalse()
            ->and($file->streams[0])->not->toBe($file->streams[1]);
    } finally {
        unlink($path);
    }
});
