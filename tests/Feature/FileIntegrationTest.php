<?php

namespace Tests\Feature;

use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Files;
use Tests\TestCase;

class FileIntegrationTest extends TestCase
{
    protected $provider = 'anthropic';

    public function test_can_store_files(): void
    {
        $response = Files::put('Hello, World!', 'text/plain', $this->provider);

        $this->assertNotEmpty($response->id);

        Files::delete($response->id, $this->provider);
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
