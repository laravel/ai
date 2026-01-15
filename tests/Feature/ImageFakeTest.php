<?php

namespace Tests\Feature;

use Exception;
use Illuminate\Support\Collection;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;
use Laravel\Ai\Prompts\QueuedImagePrompt;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;
use RuntimeException;
use Tests\TestCase;

class ImageFakeTest extends TestCase
{
    public function test_images_can_be_faked(): void
    {
        Image::fake([
            base64_encode('first-image'),
            fn (ImagePrompt $prompt) => base64_encode('second-image-'.$prompt->prompt),
            new ImageResponse(
                new Collection([new GeneratedImage(base64_encode('third-image'))]),
                new Usage,
                new Meta,
            ),
        ]);

        $response = Image::of('First prompt')->generate();
        $this->assertEquals(base64_encode('first-image'), $response->firstImage()->image);

        $response = Image::of('Second prompt')->generate();
        $this->assertEquals(base64_encode('second-image-Second prompt'), $response->firstImage()->image);

        $response = Image::of('Third prompt')->generate();
        $this->assertEquals(base64_encode('third-image'), $response->firstImage()->image);

        // Assertion tests...
        Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->prompt === 'First prompt');
        Image::assertNotGenerated(fn (ImagePrompt $prompt) => $prompt->prompt === 'Missing prompt');

        Image::assertGenerated(function (ImagePrompt $prompt) {
            return $prompt->prompt === 'First prompt';
        });
    }

    public function test_can_assert_no_images_were_generated(): void
    {
        Image::fake();

        Image::assertNothingGenerated();
    }

    public function test_images_can_be_faked_with_no_predefined_responses(): void
    {
        Image::fake();

        $response = Image::of('First prompt')->generate();
        $this->assertEquals(base64_encode('fake-image-content'), $response->firstImage()->image);

        $response = Image::of('Second prompt')->generate();
        $this->assertEquals(base64_encode('fake-image-content'), $response->firstImage()->image);
    }

    public function test_images_can_be_faked_with_a_single_closure_that_is_invoked_for_every_generation(): void
    {
        Image::fake(function (ImagePrompt $prompt) {
            return base64_encode('image-for-'.$prompt->prompt);
        });

        $response = Image::of('First prompt')->generate();
        $this->assertEquals(base64_encode('image-for-First prompt'), $response->firstImage()->image);

        $response = Image::of('Second prompt')->generate();
        $this->assertEquals(base64_encode('image-for-Second prompt'), $response->firstImage()->image);
    }

    public function test_images_can_prevent_stray_generations(): void
    {
        $this->expectException(RuntimeException::class);

        Image::fake()->preventStrayImages();

        Image::of('First prompt')->generate();
    }

    public function test_fake_closures_can_throw_exceptions(): void
    {
        $this->expectException(Exception::class);

        Image::fake(function () {
            throw new Exception('Something went wrong');
        });

        Image::of('Test prompt')->generate();
    }

    public function test_image_size_and_quality_are_recorded(): void
    {
        Image::fake();

        Image::of('A sunset')->square()->quality('high')->generate();

        Image::assertGenerated(function (ImagePrompt $prompt) {
            return $prompt->prompt === 'A sunset'
                && $prompt->size === '1:1'
                && $prompt->quality === 'high';
        });
    }

    public function test_queued_images_can_be_faked(): void
    {
        Image::fake();

        Image::of('First prompt')->queue();

        Image::assertQueued(fn (QueuedImagePrompt $prompt) => $prompt->prompt === 'First prompt');
        Image::assertNotQueued(fn (QueuedImagePrompt $prompt) => $prompt->contains('Second prompt'));

        Image::assertQueued(function (QueuedImagePrompt $prompt) {
            return $prompt->prompt === 'First prompt';
        });

        Image::assertNotQueued(function (QueuedImagePrompt $prompt) {
            return $prompt->prompt === 'Second prompt';
        });
    }

    public function test_can_assert_no_images_were_queued(): void
    {
        Image::fake();

        Image::assertNothingQueued();
    }

    public function test_queued_image_size_and_quality_are_recorded(): void
    {
        Image::fake();

        Image::of('A sunset')->landscape()->quality('low')->queue();

        Image::assertQueued(function (QueuedImagePrompt $prompt) {
            return $prompt->prompt === 'A sunset'
                && $prompt->size === '3:2'
                && $prompt->quality === 'low';
        });
    }

    public function test_timeout_can_be_passed_to_image_generation(): void
    {
        Image::fake();

        $timeout = 120;
        Image::of('Test prompt')->timeout($timeout)->generate();

        // Verify the generation was recorded
        Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->prompt === 'Test prompt');
    }

    public function test_timeout_can_be_passed_directly_to_generate(): void
    {
        Image::fake();

        $timeout = 120;
        Image::of('Test prompt')->generate(timeout: $timeout);

        // Verify the generation was recorded
        Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->prompt === 'Test prompt');
    }

    public function test_timeout_defaults_to_null_when_not_provided(): void
    {
        Image::fake();

        Image::of('Test prompt')->generate();

        Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->prompt === 'Test prompt');
    }
}
