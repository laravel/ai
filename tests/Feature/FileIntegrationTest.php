<?php

namespace Tests\Feature;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Events\FileDeleted;
use Laravel\Ai\Events\FileStored;
use Laravel\Ai\Events\StoringFile;
use Laravel\Ai\Files;
use RuntimeException;
use Tests\TestCase;

class FileIntegrationTest extends TestCase
{
    protected $provider = 'anthropic';

    public function test_can_store_files(): void
    {
        Event::fake();

        $response = Files::put('Hello, World!', 'text/plain', $this->provider);

        $this->assertNotEmpty($response->id);

        Event::assertDispatched(StoringFile::class);
        Event::assertDispatched(FileStored::class);

        Files::delete($response->id, $this->provider);

        Event::assertDispatched(FileDeleted::class);
    }

    public function test_can_store_files_from_local_paths(): void
    {
        $response = Files::putFromPath(__DIR__.'/files/document.txt', provider: $this->provider);

        $this->assertNotEmpty($response->id);

        Files::delete($response->id, $this->provider);
    }

    public function test_can_store_files_from_storage_paths(): void
    {
        Storage::disk('local')->put('document.txt', 'Hello World');

        $response = Files::putFromStorage('document.txt', disk: 'local', provider: $this->provider);

        $this->assertNotEmpty($response->id);

        Files::delete($response->id, $this->provider);
    }

    public function test_exception_is_thrown_if_stored_file_does_not_exist(): void
    {
        $this->expectException(RuntimeException::class);

        $response = Files::putFromStorage('missing-document.pdf', disk: 'local', provider: $this->provider);
    }

    public function test_can_get_files(): void
    {
        $stored = Files::put('Hello, World!', 'text/plain', $this->provider);

        $response = Files::get($stored->id, $this->provider);

        $this->assertEquals($stored->id, $response->id);
        $this->assertEquals('text/plain', $response->mime);

        Files::delete($stored->id, $this->provider);
    }

    public function test_can_delete_files(): void
    {
        $stored = Files::put('Hello, World!', 'text/plain', $this->provider);

        Files::delete($stored->id, $this->provider);

        $this->expectException(RequestException::class);

        Files::get($stored->id, $this->provider);
    }
}
