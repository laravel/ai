<?php

namespace Tests\Feature;

use Exception;
use Illuminate\Support\Collection;
use Laravel\Ai\Prompts\QueuedTranslationPrompt;
use Laravel\Ai\Prompts\TranslationPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TranslationResponse;
use Laravel\Ai\Translation;
use RuntimeException;
use Tests\TestCase;

class TranslationFakeTest extends TestCase
{
    public function test_translations_can_be_faked(): void
    {
        Translation::fake([
            'First translation',
            fn (TranslationPrompt $prompt) => 'Second translation',
            new TranslationResponse(
                'Third translation',
                new Usage,
                new Meta,
            ),
        ]);

        $response = Translation::of(base64_encode('audio-1'))->generate();
        $this->assertEquals('First translation', $response->text);

        $response = Translation::of(base64_encode('audio-2'))->generate();
        $this->assertEquals('Second translation', $response->text);

        $response = Translation::of(base64_encode('audio-3'))->generate();
        $this->assertEquals('Third translation', $response->text);

        // Assertion tests...
        Translation::assertGenerated(fn (TranslationPrompt $prompt) => true);
        Translation::assertNotGenerated(fn (TranslationPrompt $prompt) => $prompt->prompt === 'missing');
    }

    public function test_can_assert_no_translations_were_generated(): void
    {
        Translation::fake();

        Translation::assertNothingGenerated();
    }

    public function test_translations_can_be_faked_with_no_predefined_responses(): void
    {
        Translation::fake();

        $response = Translation::of(base64_encode('audio-1'))->generate();
        $this->assertEquals('Fake translation text.', $response->text);

        $response = Translation::of(base64_encode('audio-2'))->generate();
        $this->assertEquals('Fake translation text.', $response->text);
    }

    public function test_translations_can_be_faked_with_a_single_closure_that_is_invoked_for_every_generation(): void
    {
        $counter = 0;

        Translation::fake(function (TranslationPrompt $prompt) use (&$counter) {
            $counter++;

            return "Translation {$counter}";
        });

        $response = Translation::of(base64_encode('audio-1'))->generate();
        $this->assertEquals('Translation 1', $response->text);

        $response = Translation::of(base64_encode('audio-2'))->generate();
        $this->assertEquals('Translation 2', $response->text);
    }

    public function test_translations_can_prevent_stray_generations(): void
    {
        $this->expectException(RuntimeException::class);

        Translation::fake()->preventStrayTranslations();

        Translation::of(base64_encode('audio'))->generate();
    }

    public function test_fake_closures_can_throw_exceptions(): void
    {
        $this->expectException(Exception::class);

        Translation::fake(function () {
            throw new Exception('Something went wrong');
        });

        Translation::of(base64_encode('audio'))->generate();
    }

    public function test_translation_prompt_is_recorded(): void
    {
        Translation::fake();

        Translation::of(base64_encode('audio'))->prompt('Translate carefully')->generate();

        Translation::assertGenerated(function (TranslationPrompt $prompt) {
            return $prompt->prompt === 'Translate carefully';
        });
    }

    public function test_queued_translations_can_be_faked(): void
    {
        Translation::fake();

        Translation::fromPath('/path/to/audio.mp3')->queue();

        Translation::assertQueued(fn (QueuedTranslationPrompt $prompt) => $prompt->audio->path === '/path/to/audio.mp3');
        Translation::assertNotQueued(fn (QueuedTranslationPrompt $prompt) => $prompt->audio->path === '/path/to/other.mp3');

        Translation::assertQueued(function (QueuedTranslationPrompt $prompt) {
            return $prompt->audio->path === '/path/to/audio.mp3';
        });

        Translation::assertNotQueued(function (QueuedTranslationPrompt $prompt) {
            return $prompt->audio->path === '/path/to/other.mp3';
        });
    }

    public function test_can_assert_no_translations_were_queued(): void
    {
        Translation::fake();

        Translation::assertNothingQueued();
    }

    public function test_queued_translation_prompt_is_recorded(): void
    {
        Translation::fake();

        Translation::fromPath('/path/to/audio.mp3')->prompt('Translate this')->queue();

        Translation::assertQueued(function (QueuedTranslationPrompt $prompt) {
            return $prompt->prompt === 'Translate this';
        });
    }
}
