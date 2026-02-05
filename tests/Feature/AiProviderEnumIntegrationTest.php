<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Audio;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\AiProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Transcription;
use Tests\Feature\Agents\AssistantAgent;
use Tests\TestCase;

class AiProviderEnumIntegrationTest extends TestCase
{
    public function test_agent_prompt_accepts_ai_provider_enum(): void
    {
        Event::fake();

        $response = (new AssistantAgent)->prompt(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: AiProvider::Groq,
            model: 'openai/gpt-oss-20b',
        );

        $this->assertTrue(str_contains($response->text, 'Laravel'));
        $this->assertEquals('groq', $response->meta->provider);

        Event::assertDispatched(AgentPrompted::class);
    }

    public function test_agent_stream_accepts_ai_provider_enum(): void
    {
        Event::fake();

        $response = (new AssistantAgent)->stream(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: AiProvider::Groq,
            model: 'openai/gpt-oss-20b',
        );

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        $this->assertTrue(
            collect($events)->whereInstanceOf(TextDelta::class)->isNotEmpty()
        );
        $this->assertTrue(str_contains($response->text, 'Laravel'));

        Event::assertDispatched(AgentStreamed::class);
    }

    public function test_agent_queue_accepts_ai_provider_enum(): void
    {
        (new AssistantAgent)->queue(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: AiProvider::Groq,
            model: 'openai/gpt-oss-20b',
        )->then(function (AgentResponse $response) {
            $_ENV['__testing.enum_queue_response'] = $response;
        });

        $response = $_ENV['__testing.enum_queue_response'];

        $this->assertTrue(str_contains($response->text, 'Laravel'));

        unset($_ENV['__testing.enum_queue_response']);
    }

    public function test_agent_prompt_accepts_array_of_ai_provider_enum_values_for_failover(): void
    {
        $response = (new AssistantAgent)->prompt(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: [AiProvider::Groq],
            model: 'openai/gpt-oss-20b',
        );

        $this->assertTrue(str_contains($response->text, 'Laravel'));
        $this->assertEquals('groq', $response->meta->provider);
    }

    public function test_embeddings_generate_accepts_ai_provider_enum(): void
    {
        Event::fake();

        $response = Embeddings::for(['I love to watch Star Trek.'])
            ->generate(provider: AiProvider::OpenAI);

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertTrue(count($response->embeddings[0]) === 1536);
        $this->assertEquals('openai', $response->meta->provider);

        Event::assertDispatched(GeneratingEmbeddings::class);
        Event::assertDispatched(EmbeddingsGenerated::class);
    }

    public function test_audio_generate_accepts_ai_provider_enum(): void
    {
        $response = Audio::of('Hello there! How are you today?')
            ->generate(provider: AiProvider::OpenAI);

        $this->assertEquals('openai', $response->meta->provider);
    }

    public function test_transcription_generate_accepts_ai_provider_enum(): void
    {
        $audio = Audio::of('Hello there! How are you today?')->generate();

        $transcription = Transcription::of($audio->audio)
            ->generate(provider: AiProvider::OpenAI);

        $this->assertTrue(str_contains(strtolower((string) $transcription), 'how are you today'));
    }
}
