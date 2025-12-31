<?php

namespace Tests\Feature;

use Exception;
use Laravel\Ai\Audio;
use Laravel\Ai\AudioPrompt;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;
use RuntimeException;
use Tests\TestCase;

class AudioFakeTest extends TestCase
{
    public function test_audio_can_be_faked(): void
    {
        Audio::fake([
            base64_encode('first-audio'),
            fn (AudioPrompt $prompt) => base64_encode('second-audio-'.$prompt->text),
            new AudioResponse(base64_encode('third-audio'), new Meta),
        ]);

        $response = Audio::of('First text')->generate();
        $this->assertEquals(base64_encode('first-audio'), $response->audio);

        $response = Audio::of('Second text')->generate();
        $this->assertEquals(base64_encode('second-audio-Second text'), $response->audio);

        $response = Audio::of('Third text')->generate();
        $this->assertEquals(base64_encode('third-audio'), $response->audio);

        // Assertion tests...
        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->text === 'First text');
        Audio::assertNotGenerated(fn (AudioPrompt $prompt) => $prompt->text === 'Missing text');

        Audio::assertGenerated(function (AudioPrompt $prompt) {
            return $prompt->text === 'First text';
        });
    }

    public function test_can_assert_no_audio_was_generated(): void
    {
        Audio::fake();

        Audio::assertNothingGenerated();
    }

    public function test_audio_can_be_faked_with_no_predefined_responses(): void
    {
        Audio::fake();

        $response = Audio::of('First text')->generate();
        $this->assertEquals(base64_encode('fake-audio-content'), $response->audio);

        $response = Audio::of('Second text')->generate();
        $this->assertEquals(base64_encode('fake-audio-content'), $response->audio);
    }

    public function test_audio_can_be_faked_with_a_single_closure_that_is_invoked_for_every_generation(): void
    {
        Audio::fake(function (AudioPrompt $prompt) {
            return base64_encode('audio-for-'.$prompt->text);
        });

        $response = Audio::of('First text')->generate();
        $this->assertEquals(base64_encode('audio-for-First text'), $response->audio);

        $response = Audio::of('Second text')->generate();
        $this->assertEquals(base64_encode('audio-for-Second text'), $response->audio);
    }

    public function test_audio_can_prevent_stray_generations(): void
    {
        $this->expectException(RuntimeException::class);

        Audio::fake()->preventStrayAudioGenerations();

        Audio::of('First text')->generate();
    }

    public function test_fake_closures_can_throw_exceptions(): void
    {
        $this->expectException(Exception::class);

        Audio::fake(function () {
            throw new Exception('Something went wrong');
        });

        Audio::of('Test text')->generate();
    }

    public function test_audio_voice_and_instructions_are_recorded(): void
    {
        Audio::fake();

        Audio::of('Hello world')->voice('alloy')->instructions('Speak slowly')->generate();

        Audio::assertGenerated(function (AudioPrompt $prompt) {
            return $prompt->text === 'Hello world'
                && $prompt->voice === 'alloy'
                && $prompt->instructions === 'Speak slowly';
        });
    }
}
