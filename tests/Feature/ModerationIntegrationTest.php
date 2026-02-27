<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\Moderated;
use Laravel\Ai\Events\Moderating;
use Laravel\Ai\Moderation;
use Laravel\Ai\Responses\ModerationResponse;
use Tests\TestCase;

class ModerationIntegrationTest extends TestCase
{
    public function test_input_can_be_moderated(): void
    {
        Event::fake();

        $response = Moderation::check('I love programming with Laravel.');

        $this->assertInstanceOf(ModerationResponse::class, $response);
        $this->assertFalse($response->flagged);
        $this->assertNotEmpty($response->categories);
        $this->assertEquals('openai', $response->meta->provider);

        Event::assertDispatched(Moderating::class);
        Event::assertDispatched(Moderated::class);
    }

    public function test_flagged_input_is_detected(): void
    {
        $response = Moderation::check('I want hurt someone.');

        $this->assertInstanceOf(ModerationResponse::class, $response);
        $this->assertTrue($response->flagged);
        $this->assertTrue($response->flagged()->isNotEmpty());
        $this->assertNotNull($response->category('violence'));
    }

    public function test_input_can_be_moderated_with_specific_model(): void
    {
        $response = Moderation::check(
            'Hello world',
            provider: 'openai',
            model: 'omni-moderation-latest'
        );

        $this->assertInstanceOf(ModerationResponse::class, $response);
        $this->assertEquals('omni-moderation-latest', $response->meta->model);
    }

    public function test_input_can_be_moderated_using_stringable_macro(): void
    {
        $response = str('I love Laravel.')->moderate();

        $this->assertInstanceOf(ModerationResponse::class, $response);
        $this->assertFalse($response->flagged);
    }
}
