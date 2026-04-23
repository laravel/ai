<?php

use Laravel\Ai\Ai;

beforeEach(function () {
    config(['ai.providers.eleven' => [
        ...config('ai.providers.eleven'),
        'key' => 'test-key',
    ]]);
});

test('audio gateway is memoized across calls', function () {
    $provider = Ai::audioProvider('eleven');

    expect($provider->audioGateway())->toBe($provider->audioGateway());
});

test('transcription gateway is memoized across calls', function () {
    $provider = Ai::transcriptionProvider('eleven');

    expect($provider->transcriptionGateway())->toBe($provider->transcriptionGateway());
});
