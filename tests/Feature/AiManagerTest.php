<?php

use Laravel\Ai\Ai;
use Laravel\Ai\Providers\OpenAiProvider;

test('can get an openai provider instance', function () {
    expect(Ai::textProvider('openai'))->toBeInstanceOf(OpenAiProvider::class);
});

test('provider type is ensured', function () {
    Ai::audioProvider('anthropic');
})->throws(LogicException::class);
