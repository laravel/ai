<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Events\GeneratingVideo;
use Laravel\Ai\Events\VideoGenerated;
use Laravel\Ai\Video;

test('videos can be generated end to end', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    Event::fake([GeneratingVideo::class, VideoGenerated::class]);

    Storage::fake('videos');

    $response = Video::of('A calm ocean wave rolling onto a sandy beach at sunset.')
        ->seconds('4')
        ->size('1280x720')
        ->timeout(600)
        ->pollInterval(5)
        ->generate(provider: $provider, model: $model);

    expect($response->meta->provider)->toEqual($provider)
        ->and($response->meta->model)->toEqual($model)
        ->and($response->remoteId)->not->toBeNull()
        ->and($response->count())->toBeGreaterThan(0)
        ->and($response->firstVideo()->content())->not->toBeEmpty()
        ->and($response->firstVideo()->mime)->not->toBeNull();

    $path = $response->store(disk: 'videos');

    expect($path)->toBeString();
    Storage::disk('videos')->assertExists($path);

    Event::assertDispatched(GeneratingVideo::class, fn (GeneratingVideo $event) => $event->prompt->prompt !== '' && $event->model === $model);
    Event::assertDispatched(VideoGenerated::class, fn (VideoGenerated $event) => $event->response === $response);
})->with('video-providers');
