<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Audio;
use Laravel\Ai\Events\AudioGenerated;
use Laravel\Ai\Events\GeneratingAudio;
use Laravel\Ai\Files;
use Laravel\Ai\Transcription;
use Tests\TestCase;

class AudioIntegrationTest extends TestCase
{
    public function test_audio_can_be_generated_and_transcribed(): void
    {
        Event::fake();

        $response = Audio::of('Hello there! How are you today?')->generate();
        $this->assertEquals($response->meta->provider, 'openai');

        Event::assertDispatched(GeneratingAudio::class, fn (GeneratingAudio $event) => $event->prompt->timeout === 30);
        Event::assertDispatched(AudioGenerated::class, fn (AudioGenerated $event) => $event->prompt->timeout === 30);

        $transcription = Transcription::of($response->audio)->generate();
        $this->assertTrue(str_contains(strtolower((string) $transcription), 'how are you today'));
        $this->assertEquals(0, $transcription->segments->count());

        $transcription = Transcription::of($response->audio)->diarize()->generate();
        $this->assertTrue(str_contains(strtolower((string) $transcription), 'how are you today'));
        $this->assertTrue($transcription->segments->count() > 0);
    }

    public function test_transcription_can_be_generated_from_local_path()
    {
        $transcription = Files\Audio::fromPath(__DIR__.'/files/audio.mp3')
            ->transcription()
            ->generate();

        $this->assertTrue(str_contains(strtolower((string) $transcription), 'how are you today'));
    }
}
