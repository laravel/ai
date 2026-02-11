<?php

namespace Tests\Feature;

use Laravel\Ai\Ai;
use Laravel\Ai\Providers\BedrockProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use LogicException;
use Tests\TestCase;

class AiManagerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai.providers.bedrock', [
            'driver' => 'bedrock',
            'name' => 'bedrock',
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-west-2',
        ]);
    }

    public function test_can_get_an_openai_provider_instance(): void
    {
        $this->assertInstanceOf(OpenAiProvider::class, Ai::textProvider('openai'));
    }

    public function test_can_get_a_bedrock_provider_instance(): void
    {
        $this->assertInstanceOf(BedrockProvider::class, Ai::textProvider('bedrock'));
    }

    public function test_provider_type_is_ensured(): void
    {
        $this->expectException(LogicException::class);

        Ai::audioProvider('anthropic');
    }

    public function test_bedrock_does_not_support_audio(): void
    {
        $this->expectException(LogicException::class);

        Ai::audioProvider('bedrock');
    }

    public function test_bedrock_does_not_support_images(): void
    {
        $this->expectException(LogicException::class);

        Ai::imageProvider('bedrock');
    }

    public function test_bedrock_does_not_support_transcription(): void
    {
        $this->expectException(LogicException::class);

        Ai::transcriptionProvider('bedrock');
    }
}
