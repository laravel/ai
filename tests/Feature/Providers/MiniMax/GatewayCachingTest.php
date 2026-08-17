<?php

use Laravel\Ai\Ai;

beforeEach(function (): void {
    config(['ai.providers.minimax' => [
        ...config('ai.providers.minimax'),
        'key' => 'test-key',
    ]]);
});

test('audio gateway is memoized across calls', function (): void {
    $provider = Ai::audioProvider('minimax');

    expect($provider->audioGateway())->toBe($provider->audioGateway());
});
