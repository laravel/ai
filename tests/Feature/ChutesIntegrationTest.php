<?php

namespace Tests\Feature;

use Laravel\Ai\Image;
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
}
