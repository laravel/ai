<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Tests\TestCase;

class EmbeddingsIntegrationTest extends TestCase
{
    public function test_embeddings_can_be_generated(): void
    {
        Event::fake();

        $response = Embeddings::for(['I love to watch Star Trek.'])->generate();

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertTrue(count($response->embeddings[0]) === 1536);
        $this->assertEquals($response->meta->provider, 'openai');

        Event::assertDispatched(GeneratingEmbeddings::class);
        Event::assertDispatched(EmbeddingsGenerated::class);
    }

    public function test_embeddings_can_be_generated_with_custom_dimensions(): void
    {
        $response = Embeddings::for(['test text'])
            ->dimensions(256)
            ->generate();

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertEquals(256, count($response->embeddings[0]));
    }
}
