<?php

namespace Tests\Feature;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Events\FileDeleted;
use Laravel\Ai\Events\FileStored;
use Laravel\Ai\Events\StoringFile;
use Laravel\Ai\Files\Document;
use RuntimeException;
use Tests\TestCase;

class FileIntegrationTest extends TestCase
{
    protected $provider = 'anthropic';

    public function test_can_store_files(): void
    {
        Event::fake();

        $response = Document::fromString('Hello, World!', 'text/plain')->put(
            name: 'hello.txt', provider: $this->provider
        );

        $this->assertNotEmpty($response->id);

        Event::assertDispatched(StoringFile::class);
        Event::assertDispatched(FileStored::class);

        Document::fromId($response->id)->delete(provider: $this->provider);

        Event::assertDispatched(FileDeleted::class);
    }

    public function test_can_store_files_from_local_paths(): void
    {
        $response = Document::fromPath(__DIR__.'/files/document.txt')->put(
            name: 'document.txt', provider: $this->provider,
        );

        $this->assertNotEmpty($response->id);

        Document::fromId($response->id)->delete(provider: $this->provider);
    }

    public function test_can_store_files_from_storage_paths(): void
    {
        Storage::disk('local')->put('document.txt', 'Hello, World!');

        $response = Document::fromStorage('document.txt', disk: 'local')->put(
            provider: $this->provider,
        );

        $this->assertNotEmpty($response->id);

        Document::fromId($response->id)->delete(provider: $this->provider);
    }

    public function test_can_store_files_from_remote_paths(): void
    {
        $stored = Document::fromUrl(
            'https://raw.githubusercontent.com/laravel/laravel/refs/heads/12.x/README.md'
        )->put(
            provider: $this->provider,
        );

        $this->assertNotEmpty($stored->id);

        $response = Document::fromId($stored->id)->get(provider: $this->provider);

        $this->assertEquals('text/plain', $response->mime);

        Document::fromId($response->id)->delete(provider: $this->provider);
    }

    public function test_exception_is_thrown_if_stored_file_does_not_exist(): void
    {
        $this->expectException(RuntimeException::class);

        $response = Document::fromStorage('missing-document.pdf', disk: 'local')->put(
            provider: $this->provider,
        );
    }

    public function test_can_get_files(): void
    {
        $stored = Document::fromString('Hello, World!', 'text/plain')->put(
            name: 'hello.txt', provider: $this->provider
        );

        $response = Document::fromId($stored->id)->get(provider: $this->provider);

        $this->assertEquals($stored->id, $response->id);
        $this->assertEquals('text/plain', $response->mime);

        Document::fromId($response->id)->delete(provider: $this->provider);
    }

    public function test_can_delete_files(): void
    {
        $stored = Document::fromString('Hello, World!', 'text/plain')->put(
            name: 'hello.txt', provider: $this->provider
        );

        Document::fromId($stored->id)->delete(provider: $this->provider);

        $this->expectException(RequestException::class);

        Document::fromId($stored->id)->get(provider: $this->provider);
    }
}
