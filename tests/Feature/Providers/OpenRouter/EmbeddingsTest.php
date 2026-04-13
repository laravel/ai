<?php

namespace Tests\Feature\Providers\OpenRouter;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Tests\TestCase;

class EmbeddingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.openrouter' => [
            ...config('ai.providers.openrouter'),
            'key' => 'test-key',
        ]]);
    }

    public function test_embeddings_request_is_correctly_formatted(): void
    {
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ],
            'usage' => [
                'prompt_tokens' => 5,
            ],
        ])]);

        Ai::instance('openrouter')->embeddings(['Hello world']);

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'google/gemini-embedding-001'
                && $body['input'] === ['Hello world']
                && $body['dimensions'] === 1536
                && str_contains($request->url(), 'embeddings');
        });
    }

    public function test_embeddings_response_is_correctly_parsed(): void
    {
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
            ],
            'usage' => [
                'prompt_tokens' => 10,
            ],
        ])]);

        $response = Ai::instance('openrouter')->embeddings(['Hello', 'World']);

        $this->assertCount(2, $response->embeddings);
        $this->assertSame([0.1, 0.2, 0.3], $response->embeddings[0]);
        $this->assertSame([0.4, 0.5, 0.6], $response->embeddings[1]);
        $this->assertSame(10, $response->tokens);
    }

    public function test_embeddings_request_sends_bearer_token(): void
    {
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]],
            ],
            'usage' => ['prompt_tokens' => 1],
        ])]);

        Ai::instance('openrouter')->embeddings(['test']);

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer test-key');
        });
    }

    public function test_embeddings_request_uses_openrouter_base_url(): void
    {
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]],
            ],
            'usage' => ['prompt_tokens' => 1],
        ])]);

        Ai::instance('openrouter')->embeddings(['test']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://openrouter.ai/api/v1/embeddings';
        });
    }
}
