<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Contracts\Gateway\VideoGateway;
use Laravel\Ai\Contracts\Providers\VideoProvider;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\OpenAiVideoGateway;
use Laravel\Ai\Providers\Provider;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Exercises the HTTP-backed {@see VideoGateway} implementation (create, poll, binary download).
 */
class VideoGatewayTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Build a mocked video provider with the given configuration.
     */
    protected function provider(array $config = []): VideoProvider
    {
        $provider = Mockery::mock(Provider::class, VideoProvider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('providerCredentials')->andReturn(['key' => 'sk-test-key']);
        $provider->shouldReceive('additionalConfiguration')->andReturn($config);

        return $provider;
    }

    public function test_generate_video_completes_flow_against_http_stubs(): void
    {
        Sleep::fake();

        Http::fake(function ($request) {
            $url = $request->url();

            if ($request->method() === 'POST' && str_contains($url, '/v1/videos') && ! str_contains($url, '/content')) {
                return Http::response(['id' => 'vid_abc123'], 200);
            }

            if ($request->method() === 'GET' && str_ends_with($url, '/content')) {
                return Http::response('fake-mp4-binary', 200, ['Content-Type' => 'video/mp4']);
            }

            return Http::response(['status' => 'completed'], 200);
        });

        $response = (new OpenAiVideoGateway)->generateVideo(
            $this->provider(), 'video-model', 'A calm ocean', '4', '1280x720', null, 1,
        );

        $this->assertSame('fake-mp4-binary', $response->firstVideo()->content());
        $this->assertSame('vid_abc123', $response->remoteId);
    }

    public function test_generate_video_polls_until_completion(): void
    {
        Sleep::fake();

        $statuses = ['queued', 'in_progress', 'completed'];
        $poll = 0;

        Http::fake(function ($request) use (&$statuses, &$poll) {
            $url = $request->url();

            if ($request->method() === 'POST' && ! str_contains($url, '/content')) {
                return Http::response(['id' => 'vid_poll'], 200);
            }

            if (str_ends_with($url, '/content')) {
                return Http::response('binary', 200, ['Content-Type' => 'video/mp4']);
            }

            return Http::response(['status' => $statuses[$poll++] ?? 'completed'], 200);
        });

        $response = (new OpenAiVideoGateway)->generateVideo(
            $this->provider(), 'video-model', 'Polling clip', '4', '1280x720', null, 1,
        );

        $this->assertSame('binary', $response->firstVideo()->content());
        $this->assertSame(3, $poll); // queued, in_progress, completed
    }

    public function test_generate_video_honors_configured_base_url(): void
    {
        Sleep::fake();

        Http::fake(function ($request) {
            $url = $request->url();

            if ($request->method() === 'POST' && ! str_contains($url, '/content')) {
                return Http::response(['id' => 'vid_proxy'], 200);
            }

            if (str_ends_with($url, '/content')) {
                return Http::response('binary', 200, ['Content-Type' => 'video/mp4']);
            }

            return Http::response(['status' => 'completed'], 200);
        });

        (new OpenAiVideoGateway)->generateVideo(
            $this->provider(['url' => 'https://proxy.test/v1']),
            'video-model', 'Routed clip', '4', '1280x720', null, 1,
        );

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://proxy.test/v1/videos'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.openai.com'));
    }

    public function test_generate_video_throws_when_create_response_is_missing_id(): void
    {
        Sleep::fake();

        Http::fake([
            '*/videos' => Http::response(['object' => 'video'], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI video create response missing id.');

        (new OpenAiVideoGateway)->generateVideo(
            $this->provider(), 'video-model', 'No id', '4', '1280x720', null, 1,
        );
    }

    public function test_generate_video_throws_when_generation_fails(): void
    {
        Sleep::fake();

        Http::fake(function ($request) {
            if ($request->method() === 'POST' && ! str_contains($request->url(), '/content')) {
                return Http::response(['id' => 'vid_fail'], 200);
            }

            return Http::response(['status' => 'failed', 'error' => ['message' => 'moderation blocked']], 200);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('moderation blocked');

        (new OpenAiVideoGateway)->generateVideo(
            $this->provider(), 'video-model', 'Fails', '4', '1280x720', null, 1,
        );
    }

    public function test_generate_video_times_out_when_deadline_is_exceeded(): void
    {
        Sleep::fake();

        Http::fake(function ($request) {
            if ($request->method() === 'POST' && ! str_contains($request->url(), '/content')) {
                return Http::response(['id' => 'vid_timeout'], 200);
            }

            return Http::response(['status' => 'in_progress'], 200);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('timed out');

        (new OpenAiVideoGateway)->generateVideo(
            $this->provider(), 'video-model', 'Never finishes', '4', '1280x720', 0, 1,
        );
    }

    public function test_generate_video_maps_rate_limit_to_failoverable_exception(): void
    {
        Sleep::fake();

        Http::fake([
            '*/videos' => Http::response(['error' => ['message' => 'slow down']], 429),
        ]);

        $this->expectException(RateLimitedException::class);

        (new OpenAiVideoGateway)->generateVideo(
            $this->provider(), 'video-model', 'Rate limited', '4', '1280x720', null, 1,
        );
    }

    public function test_generate_video_throws_when_download_fails(): void
    {
        Sleep::fake();

        Http::fake(function ($request) {
            $url = $request->url();

            if ($request->method() === 'POST' && ! str_contains($url, '/content')) {
                return Http::response(['id' => 'vid_dl'], 200);
            }

            if (str_ends_with($url, '/content')) {
                return Http::response('boom', 500);
            }

            return Http::response(['status' => 'completed'], 200);
        });

        $this->expectException(RequestException::class);

        (new OpenAiVideoGateway)->generateVideo(
            $this->provider(), 'video-model', 'Download fails', '4', '1280x720', null, 1,
        );
    }
}
