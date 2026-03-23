<?php

namespace Tests\Feature;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Jobs\GenerateVideo;
use Laravel\Ai\Prompts\QueuedVideoPrompt;
use Laravel\Ai\Prompts\VideoPrompt;
use Laravel\Ai\Responses\Data\GeneratedVideo;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\VideoResponse;
use Laravel\Ai\Video;
use RuntimeException;
use Tests\TestCase;

class VideoFakeTest extends TestCase
{
    public function test_videos_can_be_faked(): void
    {
        Video::fake([
            'first-video-bytes',
            fn (VideoPrompt $prompt) => 'second-video-'.$prompt->prompt,
            new VideoResponse(
                new Collection([new GeneratedVideo('third-video')]),
                new Usage,
                new Meta,
            ),
        ]);

        $response = Video::of('First prompt')->generate();
        $this->assertEquals('first-video-bytes', $response->firstVideo()->content());

        $response = Video::of('Second prompt')->generate();
        $this->assertEquals('second-video-Second prompt', $response->firstVideo()->content());

        $response = Video::of('Third prompt')->generate();
        $this->assertEquals('third-video', $response->firstVideo()->content());

        Video::assertGenerated(fn (VideoPrompt $prompt) => $prompt->prompt === 'First prompt');
        Video::assertNotGenerated(fn (VideoPrompt $prompt) => $prompt->prompt === 'Missing prompt');

        Video::assertGenerated(function (VideoPrompt $prompt) {
            return $prompt->prompt === 'First prompt';
        });
    }

    public function test_can_assert_no_videos_were_generated(): void
    {
        Video::fake();

        Video::assertNothingGenerated();
    }

    public function test_videos_can_be_faked_with_no_predefined_responses(): void
    {
        Video::fake();

        $response = Video::of('First prompt')->generate();
        $this->assertEquals('fake-video-content', $response->firstVideo()->content());

        $response = Video::of('Second prompt')->generate();
        $this->assertEquals('fake-video-content', $response->firstVideo()->content());
    }

    public function test_videos_can_be_faked_with_a_single_closure_that_is_invoked_for_every_generation(): void
    {
        Video::fake(function (VideoPrompt $prompt) {
            return 'video-for-'.$prompt->prompt;
        });

        $response = Video::of('First prompt')->generate();
        $this->assertEquals('video-for-First prompt', $response->firstVideo()->content());

        $response = Video::of('Second prompt')->generate();
        $this->assertEquals('video-for-Second prompt', $response->firstVideo()->content());
    }

    public function test_videos_can_prevent_stray_generations(): void
    {
        $this->expectException(RuntimeException::class);

        Video::fake()->preventStrayVideos();

        Video::of('First prompt')->generate();
    }

    public function test_fake_closures_can_throw_exceptions(): void
    {
        $this->expectException(Exception::class);

        Video::fake(function () {
            throw new Exception('Something went wrong');
        });

        Video::of('Test prompt')->generate();
    }

    public function test_seconds_and_size_are_recorded(): void
    {
        Video::fake();

        Video::of('A sunset')->seconds('8')->size('1280x720')->generate();

        Video::assertGenerated(function (VideoPrompt $prompt) {
            return $prompt->prompt === 'A sunset'
                && $prompt->seconds === '8'
                && $prompt->size === '1280x720';
        });
    }

    public function test_queued_videos_can_be_faked(): void
    {
        Video::fake();

        Video::of('First prompt')->queue();

        Video::assertQueued(fn (QueuedVideoPrompt $prompt) => $prompt->prompt === 'First prompt');
        Video::assertNotQueued(fn (QueuedVideoPrompt $prompt) => $prompt->contains('Second prompt'));

        Video::assertQueued(function (QueuedVideoPrompt $prompt) {
            return $prompt->prompt === 'First prompt';
        });

        Video::assertNotQueued(function (QueuedVideoPrompt $prompt) {
            return $prompt->prompt === 'Second prompt';
        });
    }

    public function test_can_assert_no_videos_were_queued(): void
    {
        Video::fake();

        Video::assertNothingQueued();
    }

    public function test_generate_accepts_ai_provider_enum(): void
    {
        Video::fake();

        Video::of('Enum video')->generate(provider: Lab::OpenAI);

        Video::assertGenerated(fn (VideoPrompt $prompt) => $prompt->prompt === 'Enum video');
    }

    public function test_queued_video_accepts_ai_provider_enum(): void
    {
        Video::fake();

        Video::of('Queued enum video')->queue(provider: Lab::OpenAI);

        Video::assertQueued(fn (QueuedVideoPrompt $prompt) => $prompt->prompt === 'Queued enum video'
            && $prompt->provider === Lab::OpenAI);
    }

    public function test_queued_seconds_and_size_are_recorded(): void
    {
        Video::fake();

        Video::of('A sunset')->seconds('12')->size('720x1280')->queue();

        Video::assertQueued(function (QueuedVideoPrompt $prompt) {
            return $prompt->prompt === 'A sunset'
                && $prompt->seconds === '12'
                && $prompt->size === '720x1280';
        });
    }

    public function test_queue_on_queue_remains_fluent_with_then(): void
    {
        Bus::fake();

        Video::of('Queued with custom queue')
            ->queue(provider: 'openai')
            ->onQueue('custom-videos')
            ->then(function (): void {
                //
            });

        Bus::assertDispatched(GenerateVideo::class, function (GenerateVideo $job): bool {
            return $job->queue === 'custom-videos';
        });
    }

    public function test_queue_uses_default_queue_when_video_queue_config_is_unset_or_empty(): void
    {
        config(['ai.video_queue' => null]);

        Bus::fake();

        Video::of('Default queue test')->queue(provider: 'openai');

        Bus::assertDispatched(GenerateVideo::class, function (GenerateVideo $job): bool {
            return $job->queue === null;
        });

        Bus::fake();

        config(['ai.video_queue' => '']);

        Video::of('Empty string queue test')->queue(provider: 'openai');

        Bus::assertDispatched(GenerateVideo::class, function (GenerateVideo $job): bool {
            return $job->queue === null;
        });
    }

    public function test_queue_uses_config_video_queue_when_set(): void
    {
        config(['ai.video_queue' => 'from-config']);

        Bus::fake();

        Video::of('Config queue test')->queue(provider: 'openai');

        Bus::assertDispatched(GenerateVideo::class, function (GenerateVideo $job): bool {
            return $job->queue === 'from-config';
        });
    }

    public function test_queue_on_queue_overrides_config_video_queue(): void
    {
        config(['ai.video_queue' => 'from-config']);

        Bus::fake();

        Video::of('Override queue')->queue(provider: 'openai')->onQueue('explicit-queue');

        Bus::assertDispatched(GenerateVideo::class, function (GenerateVideo $job): bool {
            return $job->queue === 'explicit-queue';
        });
    }
}
