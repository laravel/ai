<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Stores;
use Tests\TestCase;

use function Laravel\Ai\agent;

class OpenAiBaseUrlTest extends TestCase
{
    protected string $customUrl = 'http://localhost:1234/v1';

    public function test_openai_text_requests_use_the_configured_base_url(): void
    {
        $this->configureOpenAiProvider($this->customUrl);

        Http::fake([
            '*' => Http::response([
                'id' => 'resp_123',
                'status' => 'completed',
                'model' => 'gpt-5.4',
                'output' => [[
                    'type' => 'message',
                    'status' => 'completed',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Hello from local model',
                    ]],
                ]],
                'usage' => [
                    'input_tokens' => 1,
                    'output_tokens' => 1,
                ],
            ]),
        ]);

        $response = agent()->prompt('Hello', provider: 'openai');

        $this->assertSame('Hello from local model', $response->text);

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', "{$this->customUrl}/responses");
    }

    public function test_openai_file_requests_use_the_configured_base_url(): void
    {
        $this->configureOpenAiProvider($this->customUrl);

        Http::fake(function (Request $request) {
            return match ([$request->method(), $request->url()]) {
                ['POST', "{$this->customUrl}/files"] => Http::response(['id' => 'file_123']),
                ['GET', "{$this->customUrl}/files/file_123"] => Http::response(['id' => 'file_123']),
                ['DELETE', "{$this->customUrl}/files/file_123"] => Http::response(),
                default => Http::response(['unexpected_url' => $request->url()], 500),
            };
        });

        $stored = Files::put(
            Document::fromString('Hello, World!', 'text/plain')->as('hello.txt'),
            provider: 'openai',
        );

        $retrieved = Files::get($stored->id, provider: 'openai');

        Files::delete($stored->id, provider: 'openai');

        $this->assertSame('file_123', $stored->id);
        $this->assertSame('file_123', $retrieved->id);

        Http::assertSentCount(3);
        $this->assertRequestSent('POST', "{$this->customUrl}/files");
        $this->assertRequestSent('GET', "{$this->customUrl}/files/file_123");
        $this->assertRequestSent('DELETE', "{$this->customUrl}/files/file_123");
    }

    public function test_openai_store_requests_use_the_configured_base_url(): void
    {
        $this->configureOpenAiProvider($this->customUrl);

        Http::fake(function (Request $request) {
            return match ([$request->method(), $request->url()]) {
                ['POST', "{$this->customUrl}/vector_stores"] => Http::response(['id' => 'vs_123']),
                ['GET', "{$this->customUrl}/vector_stores/vs_123"] => Http::response([
                    'id' => 'vs_123',
                    'name' => 'Local Store',
                    'status' => 'completed',
                    'file_counts' => [
                        'completed' => 0,
                        'in_progress' => 0,
                        'failed' => 0,
                    ],
                ]),
                ['POST', "{$this->customUrl}/vector_stores/vs_123/files"] => Http::response(['id' => 'vsfile_123']),
                ['DELETE', "{$this->customUrl}/vector_stores/vs_123/files/vsfile_123"] => Http::response(['deleted' => true]),
                ['DELETE', "{$this->customUrl}/vector_stores/vs_123"] => Http::response(['deleted' => true]),
                default => Http::response(['unexpected_url' => $request->url()], 500),
            };
        });

        $store = Stores::create('Local Store', provider: 'openai');
        $document = $store->add('file_123');
        $removed = $store->remove($document->id());
        $deleted = $store->delete();

        $this->assertSame('vs_123', $store->id);
        $this->assertSame('Local Store', $store->name);
        $this->assertSame('vsfile_123', $document->id());
        $this->assertTrue($removed);
        $this->assertTrue($deleted);

        Http::assertSentCount(5);
        $this->assertRequestSent('POST', "{$this->customUrl}/vector_stores");
        $this->assertRequestSent('GET', "{$this->customUrl}/vector_stores/vs_123");
        $this->assertRequestSent('POST', "{$this->customUrl}/vector_stores/vs_123/files");
        $this->assertRequestSent('DELETE', "{$this->customUrl}/vector_stores/vs_123/files/vsfile_123");
        $this->assertRequestSent('DELETE', "{$this->customUrl}/vector_stores/vs_123");
    }

    public function test_openai_requests_fall_back_to_the_default_base_url(): void
    {
        $this->configureOpenAiProvider();

        Http::fake([
            '*' => Http::response([
                'id' => 'resp_456',
                'status' => 'completed',
                'model' => 'gpt-5.4',
                'output' => [[
                    'type' => 'message',
                    'status' => 'completed',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Hello from OpenAI',
                    ]],
                ]],
                'usage' => [
                    'input_tokens' => 1,
                    'output_tokens' => 1,
                ],
            ]),
        ]);

        $response = agent()->prompt('Hello', provider: 'openai');

        $this->assertSame('Hello from OpenAI', $response->text);

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', 'https://api.openai.com/v1/responses');
    }

    protected function configureOpenAiProvider(?string $url = null): void
    {
        config(['ai.providers.openai' => array_filter([
            ...config('ai.providers.openai'),
            'key' => 'test-key',
            'url' => $url,
        ])]);
    }

    protected function assertRequestSent(string $method, string $url): void
    {
        Http::assertSent(fn (Request $request) => $request->method() === $method
            && $request->url() === $url);
    }
}
