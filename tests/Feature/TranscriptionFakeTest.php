<?php

namespace Tests\Feature;

use Exception;
use Illuminate\Support\Collection;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\QueuedTranscriptionPrompt;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;
use RuntimeException;
use Tests\TestCase;

class TranscriptionFakeTest extends TestCase
{
    public function test_transcriptions_can_be_faked(): void
    {
        Transcription::fake([
            'First transcription',
            fn (TranscriptionPrompt $prompt) => 'Second transcription',
            new TranscriptionResponse(
                'Third transcription',
                new Collection([new TranscriptionSegment('Third transcription', 'Speaker 1', 0.0, 1.0)]),
                new Usage,
                new Meta,
            ),
        ]);

        $response = Transcription::of(base64_encode('audio-1'))->generate();
        $this->assertEquals('First transcription', $response->text);

        $response = Transcription::of(base64_encode('audio-2'))->generate();
        $this->assertEquals('Second transcription', $response->text);

        $response = Transcription::of(base64_encode('audio-3'))->generate();
        $this->assertEquals('Third transcription', $response->text);

        // Assertion tests...
        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt) => true);
        Transcription::assertNotGenerated(fn (TranscriptionPrompt $prompt) => $prompt->language === 'fr');
    }

    public function test_can_assert_no_transcriptions_were_generated(): void
    {
        Transcription::fake();

        Transcription::assertNothingGenerated();
    }

    public function test_transcriptions_can_be_faked_with_no_predefined_responses(): void
    {
        Transcription::fake();

        $response = Transcription::of(base64_encode('audio-1'))->generate();
        $this->assertEquals('Fake transcription text.', $response->text);

        $response = Transcription::of(base64_encode('audio-2'))->generate();
        $this->assertEquals('Fake transcription text.', $response->text);
    }

    public function test_transcriptions_can_be_faked_with_a_single_closure_that_is_invoked_for_every_generation(): void
    {
        $counter = 0;

        Transcription::fake(function (TranscriptionPrompt $prompt) use (&$counter) {
            $counter++;

            return "Transcription {$counter}";
        });

        $response = Transcription::of(base64_encode('audio-1'))->generate();
        $this->assertEquals('Transcription 1', $response->text);

        $response = Transcription::of(base64_encode('audio-2'))->generate();
        $this->assertEquals('Transcription 2', $response->text);
    }

    public function test_transcriptions_can_prevent_stray_generations(): void
    {
        $this->expectException(RuntimeException::class);

        Transcription::fake()->preventStrayTranscriptions();

        Transcription::of(base64_encode('audio'))->generate();
    }

    public function test_fake_closures_can_throw_exceptions(): void
    {
        $this->expectException(Exception::class);

        Transcription::fake(function () {
            throw new Exception('Something went wrong');
        });

        Transcription::of(base64_encode('audio'))->generate();
    }

    public function test_transcription_language_and_diarize_are_recorded(): void
    {
        Transcription::fake();

        Transcription::of(base64_encode('audio'))->language('en')->diarize()->generate();

        Transcription::assertGenerated(function (TranscriptionPrompt $prompt) {
            return $prompt->language === 'en' && $prompt->isDiarized();
        });
    }

    public function test_fake_transcriptions_include_segments(): void
    {
        Transcription::fake(['Hello world']);

        $response = Transcription::of(base64_encode('audio'))->generate();

        $this->assertCount(1, $response->segments);
        $this->assertEquals('Hello world', $response->segments[0]->text);
        $this->assertEquals('Speaker 1', $response->segments[0]->speaker);
    }

    public function test_queued_transcriptions_can_be_faked(): void
    {
        Transcription::fake();

        Transcription::fromPath('/path/to/audio.mp3')->queue();

        Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt) => $prompt->audio->path === '/path/to/audio.mp3');
        Transcription::assertNotQueued(fn (QueuedTranscriptionPrompt $prompt) => $prompt->audio->path === '/path/to/other.mp3');

        Transcription::assertQueued(function (QueuedTranscriptionPrompt $prompt) {
            return $prompt->audio->path === '/path/to/audio.mp3';
        });

        Transcription::assertNotQueued(function (QueuedTranscriptionPrompt $prompt) {
            return $prompt->audio->path === '/path/to/other.mp3';
        });
    }

    public function test_can_assert_no_transcriptions_were_queued(): void
    {
        Transcription::fake();

        Transcription::assertNothingQueued();
    }

    public function test_generate_accepts_ai_provider_enum(): void
    {
        Transcription::fake();

        Transcription::of(base64_encode('audio'))->generate(provider: Lab::OpenAI);

        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt) => true);
    }

    public function test_queued_transcription_accepts_ai_provider_enum(): void
    {
        Transcription::fake();

        Transcription::fromPath('/path/to/audio.mp3')->queue(provider: Lab::ElevenLabs);

        Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt) => $prompt->provider === Lab::ElevenLabs);
    }

    public function test_queued_transcription_language_and_diarize_are_recorded(): void
    {
        Transcription::fake();

        Transcription::fromPath('/path/to/audio.mp3')->language('es')->diarize()->queue();

        Transcription::assertQueued(function (QueuedTranscriptionPrompt $prompt) {
            return $prompt->language === 'es' && $prompt->isDiarized();
        });
    }

    public function test_transcription_can_have_timeouts(): void
    {
        Transcription::fake();

        Transcription::of(base64_encode('audio'))->timeout(60)->generate();

        Transcription::assertGenerated(function (TranscriptionPrompt $prompt) {
            return $prompt->timeout === 60;
        });
    }
}
