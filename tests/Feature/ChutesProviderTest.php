<?php

namespace Tests\Feature;

use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Gateway\ChutesAudioGateway;
use Laravel\Ai\Gateway\ChutesEmbeddingGateway;
use Laravel\Ai\Gateway\ChutesImageGateway;
use Laravel\Ai\Providers\ChutesProvider;
use Tests\TestCase;

class ChutesProviderTest extends TestCase
{
    public function test_can_resolve_chutes_provider(): void
    {
        $this->assertInstanceOf(ChutesProvider::class, Ai::textProvider('chutes'));
    }

    public function test_chutes_is_a_text_provider(): void
    {
        $provider = Ai::textProvider('chutes');

        $this->assertInstanceOf(TextProvider::class, $provider);
    }

    public function test_chutes_is_an_image_provider(): void
    {
        $provider = Ai::imageProvider('chutes');

        $this->assertInstanceOf(ImageProvider::class, $provider);
    }

    public function test_chutes_is_an_audio_provider(): void
    {
        $provider = Ai::audioProvider('chutes');

        $this->assertInstanceOf(AudioProvider::class, $provider);
    }

    public function test_chutes_is_an_embedding_provider(): void
    {
        $provider = Ai::embeddingProvider('chutes');

        $this->assertInstanceOf(EmbeddingProvider::class, $provider);
    }

    public function test_chutes_is_a_transcription_provider(): void
    {
        $provider = Ai::transcriptionProvider('chutes');

        $this->assertInstanceOf(TranscriptionProvider::class, $provider);
    }

    public function test_default_text_model(): void
    {
        $provider = Ai::textProvider('chutes');

        $this->assertEquals('deepseek-ai/DeepSeek-V3', $provider->defaultTextModel());
    }

    public function test_cheapest_text_model(): void
    {
        $provider = Ai::textProvider('chutes');

        $this->assertEquals('unsloth/gemma-3-4b-it', $provider->cheapestTextModel());
    }

    public function test_smartest_text_model(): void
    {
        $provider = Ai::textProvider('chutes');

        $this->assertEquals('moonshotai/Kimi-K2.5', $provider->smartestTextModel());
    }

    public function test_default_image_model(): void
    {
        $provider = Ai::imageProvider('chutes');

        $this->assertEquals('FLUX.1-schnell', $provider->defaultImageModel());
    }

    public function test_image_gateway_is_chutes_gateway(): void
    {
        $provider = Ai::imageProvider('chutes');

        $this->assertInstanceOf(ChutesImageGateway::class, $provider->imageGateway());
    }

    public function test_default_audio_model(): void
    {
        $provider = Ai::audioProvider('chutes');

        $this->assertEquals('kokoro', $provider->defaultAudioModel());
    }

    public function test_default_transcription_model(): void
    {
        $provider = Ai::transcriptionProvider('chutes');

        $this->assertEquals('whisper-large-v3', $provider->defaultTranscriptionModel());
    }

    public function test_audio_gateway_is_chutes_gateway(): void
    {
        $provider = Ai::audioProvider('chutes');

        $this->assertInstanceOf(ChutesAudioGateway::class, $provider->audioGateway());
    }

    public function test_transcription_gateway_is_chutes_gateway(): void
    {
        $provider = Ai::transcriptionProvider('chutes');

        $this->assertInstanceOf(ChutesAudioGateway::class, $provider->transcriptionGateway());
    }

    public function test_default_embedding_model(): void
    {
        $provider = Ai::embeddingProvider('chutes');

        $this->assertEquals('Qwen/Qwen3-Embedding-0.6B', $provider->defaultEmbeddingsModel());
    }

    public function test_default_embedding_dimensions(): void
    {
        $provider = Ai::embeddingProvider('chutes');

        $this->assertEquals(1024, $provider->defaultEmbeddingsDimensions());
    }

    public function test_embedding_gateway_is_chutes_gateway(): void
    {
        $provider = Ai::embeddingProvider('chutes');

        $this->assertInstanceOf(ChutesEmbeddingGateway::class, $provider->embeddingGateway());
    }

    public function test_default_image_options_square(): void
    {
        $provider = Ai::imageProvider('chutes');
        $options = $provider->defaultImageOptions('1:1', 'medium');

        $this->assertEquals(1024, $options['width']);
        $this->assertEquals(1024, $options['height']);
        $this->assertEquals(10, $options['steps']);
        $this->assertEquals(7.5, $options['guidance_scale']);
    }

    public function test_default_image_options_landscape(): void
    {
        $provider = Ai::imageProvider('chutes');
        $options = $provider->defaultImageOptions('3:2', 'high');

        $this->assertEquals(1536, $options['width']);
        $this->assertEquals(1024, $options['height']);
        $this->assertEquals(20, $options['steps']);
    }

    public function test_default_image_options_portrait(): void
    {
        $provider = Ai::imageProvider('chutes');
        $options = $provider->defaultImageOptions('2:3', 'low');

        $this->assertEquals(1024, $options['width']);
        $this->assertEquals(1536, $options['height']);
        $this->assertEquals(5, $options['steps']);
    }

    public function test_default_image_options_with_null_defaults(): void
    {
        $provider = Ai::imageProvider('chutes');
        $options = $provider->defaultImageOptions();

        $this->assertEquals(1024, $options['width']);
        $this->assertEquals(1024, $options['height']);
        $this->assertEquals(10, $options['steps']);
        $this->assertEquals(7.5, $options['guidance_scale']);
    }

    public function test_provider_name(): void
    {
        $provider = Ai::textProvider('chutes');

        $this->assertEquals('chutes', $provider->name());
    }

    public function test_provider_driver(): void
    {
        $provider = Ai::textProvider('chutes');

        $this->assertEquals('chutes', $provider->driver());
    }
}
