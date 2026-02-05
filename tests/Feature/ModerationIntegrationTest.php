<?php

namespace Tests\Feature;

use Laravel\Ai\Moderation;
use Tests\TestCase;

class ModerationIntegrationTest extends TestCase
{
    public function test_can_moderate_single_input(): void
    {
        if (! getenv('OPENAI_API_KEY')) {
            $this->markTestSkipped('OpenAI API key not configured.');
        }

        $response = Moderation::of('I love programming')->moderate();

        $this->assertCount(1, $response);
        $this->assertFalse($response->first()->flagged);
        $this->assertIsArray($response->first()->categories);
        $this->assertIsArray($response->first()->categoryScores);
    }

    public function test_can_moderate_array_of_inputs(): void
    {
        if (! getenv('OPENAI_API_KEY')) {
            $this->markTestSkipped('OpenAI API key not configured.');
        }

        $response = Moderation::of([
            'I love learning new things',
            'The weather is nice today',
        ])->moderate();

        $this->assertCount(2, $response);
        $this->assertFalse($response->flagged());
    }
}
