<?php

namespace Tests\Feature\Providers\Mistral;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

class EmbeddingTest extends MistralTestCase
{
    public function test_embeddings_request_includes_model_and_input(): void
    {
        Http::fake(['*' => $this->fakeEmbeddingsResponse()]);

        Embeddings::for(['Hello world'])->generate(provider: 'mistral', model: 'mistral-embed');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'mistral-embed'
                && $body['input'] === ['Hello world']
                && $request->url() === 'https://api.mistral.ai/v1/embeddings';
        });
    }

    public function test_embeddings_response_is_correctly_parsed(): void
    {
        Http::fake(['*' => $this->fakeEmbeddingsResponse()]);

        $response = Embeddings::for(['Hello world'])->generate(provider: 'mistral', model: 'mistral-embed');

        $this->assertCount(1, $response->embeddings);
        $this->assertCount(3, $response->embeddings[0]);
        $this->assertSame(10, $response->tokens);
        $this->assertSame('mistral', $response->meta->provider);
    }

    public function test_embeddings_request_sends_bearer_token(): void
    {
        Http::fake(['*' => $this->fakeEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'mistral', model: 'mistral-embed');

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer test-key');
        });
    }

    public function test_multiple_inputs_return_multiple_embeddings(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'embd-123',
            'object' => 'list',
            'model' => 'mistral-embed',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
            ],
            'usage' => ['total_tokens' => 20],
        ])]);

        $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'mistral', model: 'mistral-embed');

        $this->assertCount(2, $response->embeddings);
    }

    protected function fakeEmbeddingsResponse()
    {
        return Http::response([
            'id' => 'embd-123',
            'object' => 'list',
            'model' => 'mistral-embed',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ],
            'usage' => ['total_tokens' => 10],
        ]);
    }
}
