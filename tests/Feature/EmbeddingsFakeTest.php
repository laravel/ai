<?php

namespace Tests\Feature;

use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\AiProvider;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Prompts\QueuedEmbeddingsPrompt;
use RuntimeException;
use Tests\TestCase;

class EmbeddingsFakeTest extends TestCase
{
    public function test_can_fake_embeddings(): void
    {
        Embeddings::fake();

        $response = Embeddings::for(['Hello world'])->generate();

        $this->assertCount(1, $response);
        $this->assertCount(1536, $response->first());
    }

    public function test_can_fake_embeddings_with_custom_dimensions(): void
    {
        Embeddings::fake();

        $response = Embeddings::for(['Hello world'])->dimensions(512)->generate();

        $this->assertCount(1, $response);
        $this->assertCount(512, $response->first());
    }

    public function test_can_fake_embeddings_with_multiple_inputs(): void
    {
        Embeddings::fake();

        $response = Embeddings::for(['Hello', 'World', 'Test'])->generate();

        $this->assertCount(3, $response);
    }

    public function test_can_fake_embeddings_with_custom_response(): void
    {
        $customEmbedding = array_fill(0, 100, 0.5);

        Embeddings::fake([
            [$customEmbedding],
        ]);

        $response = Embeddings::for(['Hello world'])->dimensions(100)->generate();

        $this->assertEquals($customEmbedding, $response->first());
    }

    public function test_can_fake_embeddings_with_closure(): void
    {
        Embeddings::fake(function (EmbeddingsPrompt $prompt) {
            return array_map(
                fn () => array_fill(0, $prompt->dimensions, 0.1),
                $prompt->inputs
            );
        });

        $response = Embeddings::for(['Hello', 'World'])->dimensions(256)->generate();

        $this->assertCount(2, $response);
        $this->assertCount(256, $response->first());
    }

    public function test_can_assert_embeddings_generated(): void
    {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->generate();

        Embeddings::assertGenerated(function (EmbeddingsPrompt $prompt) {
            return in_array('Hello world', $prompt->inputs);
        });
    }

    public function test_can_assert_embeddings_not_generated(): void
    {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->generate();

        Embeddings::assertNotGenerated(function (EmbeddingsPrompt $prompt) {
            return in_array('Goodbye', $prompt->inputs);
        });
    }

    public function test_can_assert_nothing_generated(): void
    {
        Embeddings::fake();

        Embeddings::assertNothingGenerated();
    }

    public function test_fake_embeddings_are_normalized(): void
    {
        $embedding = Embeddings::fakeEmbedding(100);

        // Check it has the right dimensions...
        $this->assertCount(100, $embedding);

        // Check it's normalized (magnitude ~= 1)...
        $magnitude = sqrt(array_sum(array_map(fn ($v) => $v * $v, $embedding)));
        $this->assertEqualsWithDelta(1.0, $magnitude, 0.0001);
    }

    public function test_can_prevent_stray_embeddings_generations(): void
    {
        $this->expectException(RuntimeException::class);

        Embeddings::fake()->preventStrayEmbeddings();

        Embeddings::for(['Hello world'])->generate();
    }

    public function test_queued_embeddings_can_be_faked(): void
    {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->queue();

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt) => $prompt->contains('Hello'));
        Embeddings::assertNotQueued(fn (QueuedEmbeddingsPrompt $prompt) => $prompt->contains('Goodbye'));

        Embeddings::assertQueued(function (QueuedEmbeddingsPrompt $prompt) {
            return in_array('Hello world', $prompt->inputs);
        });

        Embeddings::assertNotQueued(function (QueuedEmbeddingsPrompt $prompt) {
            return in_array('Goodbye', $prompt->inputs);
        });
    }

    public function test_can_assert_no_embeddings_were_queued(): void
    {
        Embeddings::fake();

        Embeddings::assertNothingQueued();
    }

    public function test_generate_accepts_ai_provider_enum(): void
    {
        Embeddings::fake();

        Embeddings::for(['Enum test'])->generate(provider: AiProvider::OpenAI);

        Embeddings::assertGenerated(function (EmbeddingsPrompt $prompt) {
            return in_array('Enum test', $prompt->inputs);
        });
    }

    public function test_queued_embeddings_accept_ai_provider_enum(): void
    {
        Embeddings::fake();

        Embeddings::for(['Queued enum'])->queue(provider: AiProvider::Gemini);

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt) => $prompt->contains('Queued enum')
            && $prompt->provider === AiProvider::Gemini);
    }

    public function test_queued_embeddings_dimensions_are_recorded(): void
    {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->dimensions(256)->queue();

        Embeddings::assertQueued(function (QueuedEmbeddingsPrompt $prompt) {
            return $prompt->dimensions === 256 && $prompt->count() === 1;
        });
    }
}
