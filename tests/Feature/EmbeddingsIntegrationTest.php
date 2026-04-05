<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Video;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Tests\TestCase;

class EmbeddingsIntegrationTest extends TestCase
{
    public function test_embeddings_can_be_generated(): void
    {
        Event::fake();

        $response = Embeddings::for(['I love to watch Star Trek.'])->generate();

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertCount(1536, $response->embeddings[0]);
        $this->assertEquals('openai', $response->meta->provider);

        Event::assertDispatched(GeneratingEmbeddings::class, fn (GeneratingEmbeddings $event) => $event->prompt->timeout === 30);
        Event::assertDispatched(EmbeddingsGenerated::class, fn (EmbeddingsGenerated $event) => $event->prompt->timeout === 30);
    }

    public function test_embeddings_can_be_generated_with_custom_dimensions(): void
    {
        $response = Embeddings::for(['test text'])
            ->dimensions(256)
            ->generate();

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertEquals(256, count($response->embeddings[0]));
    }

    public function test_gemini_multimodal_embeddings_require_preview_model(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('gemini-embedding-2-preview');

        Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->generate(provider: 'gemini', model: 'gemini-embedding-001');
    }

    public function test_gemini_provider_file_embeddings_resolve_to_file_uris(): void
    {
        $this->configureGeminiProvider();

        Http::fake(function (Request $request) {
            return match ([$request->method(), $request->url()]) {
                ['GET', 'https://generativelanguage.googleapis.com/v1beta/files/file_123'] => Http::response([
                    'name' => 'files/file_123',
                    'mimeType' => 'image/png',
                    'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/file_123',
                ]),
                ['POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2-preview:embedContent'] => Http::response([
                    'embedding' => [
                        'values' => [0.1, 0.2, 0.3],
                    ],
                ]),
                default => Http::response(['unexpected_url' => $request->url()], 500),
            };
        });

        $response = Embeddings::for([
            Image::fromId('file_123'),
        ])->generate(provider: 'gemini', model: 'gemini-embedding-2-preview');

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2-preview:embedContent'
            && $request['content']['parts'][0]['file_data']['file_uri'] === 'https://generativelanguage.googleapis.com/v1beta/files/file_123');
    }

    public function test_gemini_multimodal_embeddings_accept_canonical_model_names(): void
    {
        $this->configureGeminiProvider();

        Http::fake(fn () => Http::response([
            'embedding' => [
                'values' => [0.1, 0.2, 0.3],
            ],
        ]));

        $response = Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->generate(provider: 'gemini', model: 'models/gemini-embedding-2-preview');

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), 'gemini-embedding-2-preview:embedContent'));
    }

    public function test_gemini_remote_video_embeddings_preserve_file_uris(): void
    {
        $this->configureGeminiProvider();

        Http::fake(fn () => Http::response([
            'embedding' => [
                'values' => [0.1, 0.2, 0.3],
            ],
        ]));

        $response = Embeddings::for([
            Video::fromUrl('https://www.youtube.com/watch?v=demo'),
        ])->generate(provider: 'gemini', model: 'gemini-embedding-2-preview');

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && data_get($request->data(), 'content.parts.0.file_data.file_uri') === 'https://www.youtube.com/watch?v=demo');
    }

    public function test_openai_rejects_non_text_embeddings_inputs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Provider [openai] only supports text embeddings inputs.');

        Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->generate(provider: 'openai');
    }

    public function test_voyage_ai_accepts_image_embeddings_inputs(): void
    {
        $this->configureVoyageAiProvider();

        Http::fake(fn () => Http::response([
            'data' => [
                ['embedding' => [0.1, 0.2, 0.3]],
            ],
            'model' => 'voyage-multimodal-3',
            'usage' => [
                'total_tokens' => 1,
            ],
        ]));

        $response = Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-multimodal-3');

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/multimodalembeddings'));
    }

    public function test_cached_remote_embeddings_do_not_fetch_remote_metadata(): void
    {
        config([
            'cache.default' => 'array',
            'ai.caching.embeddings.store' => 'array',
        ]);

        Http::preventStrayRequests();

        $calls = 0;

        Embeddings::fake(function () use (&$calls) {
            $calls++;

            return [array_fill(0, 100, 0.1)];
        });

        $request = fn () => Embeddings::for([
            Document::fromUrl('https://example.com/manual.pdf'),
        ])->cache(60)->generate();

        $request();
        $request();

        $this->assertSame(1, $calls);
        Http::assertNothingSent();
    }

    protected function configureGeminiProvider(): void
    {
        config(['ai.providers.gemini' => [
            ...config('ai.providers.gemini'),
            'key' => 'test-key',
        ]]);
    }

    protected function configureVoyageAiProvider(): void
    {
        config(['ai.providers.voyageai' => [
            ...config('ai.providers.voyageai'),
            'key' => 'test-key',
        ]]);
    }
}
