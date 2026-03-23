<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Contracts\Gateway\VideoGateway;
use Laravel\Ai\Contracts\Providers\VideoProvider;
use Laravel\Ai\Gateway\OpenAiVideoGateway;
use Mockery;
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

        $provider = Mockery::mock(VideoProvider::class);
        $provider->shouldReceive('name')->andReturn('test');
        $provider->shouldReceive('providerCredentials')->andReturn(['key' => 'sk-test-key']);

        $gateway = new OpenAiVideoGateway;

        $response = $gateway->generateVideo(
            $provider,
            'video-model',
            'A calm ocean',
            '4',
            '1280x720',
            null,
            1,
        );

        $this->assertSame('fake-mp4-binary', $response->firstVideo()->content());
        $this->assertSame('vid_abc123', $response->remoteId);
    }
}
