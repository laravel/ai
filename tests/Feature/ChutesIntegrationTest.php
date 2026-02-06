<?php

namespace Tests\Feature;

use Laravel\Ai\Audio;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Image;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Transcription;
use Tests\Feature\Agents\AssistantAgent;
use Tests\TestCase;

use function Laravel\Ai\agent;

class ChutesIntegrationTest extends TestCase
{
    protected $model = 'deepseek-ai/DeepSeek-V3';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai.providers.chutes', [
            'driver' => 'chutes',
            'key' => env('CHUTES_API_KEY'),
            'url' => env('CHUTES_BASE_URL', 'https://llm.chutes.ai/v1'),
        ]);
    }

    public function test_text_generation(): void
    {
        $agent = new AssistantAgent;

        $response = $agent->prompt(
            'What is 2 + 2? Reply with just the number.',
            provider: 'chutes',
            model: $this->model,
        );

        $this->assertNotEmpty($response->text);
        $this->assertStringContainsString('4', $response->text);
        $this->assertEquals('chutes', $response->meta->provider);
    }

    public function test_ad_hoc_agent_prompt(): void
    {
        $response = agent()->prompt(
            'What is the capital of France? Reply with just the city name.',
            provider: 'chutes',
            model: $this->model,
        );

        $this->assertNotEmpty($response->text);
        $this->assertStringContainsString('Paris', $response->text);
    }

    public function test_streaming(): void
    {
        $agent = new AssistantAgent;

        $response = $agent->stream(
            'What is 2 + 2? Reply with just the number.',
            provider: 'chutes',
            model: $this->model,
        )->then(function (StreamedAgentResponse $response) {
            $_SERVER['__testing.chutes_response'] = $response;
        });

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        $this->assertTrue(
            collect($events)
                ->whereInstanceOf(TextDelta::class)
                ->isNotEmpty()
        );

        $this->assertStringContainsString('4', $response->text);
        $this->assertCount(count($events), $_SERVER['__testing.chutes_response']->events);

        unset($_SERVER['__testing.chutes_response']);
    }

    public function test_image_generation(): void
    {
        $response = Image::of('A small red cube on a white background, minimalist')
            ->square()
            ->quality('low')
            ->timeout(120)
            ->generate(provider: 'chutes', model: 'FLUX.1-schnell');

        $this->assertNotNull($response->firstImage());
        $this->assertNotEmpty($response->firstImage()->image);
        $this->assertEquals('chutes', $response->meta->provider);
        $this->assertEquals('FLUX.1-schnell', $response->meta->model);
    }

    public function test_audio_generation(): void
    {
        $response = Audio::of('Hello, how are you today?')
            ->generate(provider: 'chutes', model: 'kokoro');

        $this->assertNotEmpty($response->audio);
        $this->assertEquals('audio/wav', $response->mimeType());
        $this->assertEquals('chutes', $response->meta->provider);
        $this->assertEquals('kokoro', $response->meta->model);
    }

    public function test_transcription(): void
    {
        $audio = Audio::of('Hello, how are you today?')
            ->generate(provider: 'chutes', model: 'kokoro');

        $transcription = Transcription::of($audio->audio)
            ->generate(provider: 'chutes', model: 'whisper-large-v3');

        $this->assertNotEmpty((string) $transcription);
        $this->assertEquals('chutes', $transcription->meta->provider);
        $this->assertEquals('whisper-large-v3', $transcription->meta->model);
    }

    public function test_embeddings(): void
    {
        $response = Embeddings::for(['Hello world', 'How are you?'])
            ->dimensions(1024)
            ->generate(provider: 'chutes', model: 'Qwen/Qwen3-Embedding-0.6B');

        $this->assertCount(2, $response);
        $this->assertCount(1024, $response->first());
        $this->assertEquals('chutes', $response->meta->provider);
    }
}
