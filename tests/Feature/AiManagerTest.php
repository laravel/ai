<?php

use Laravel\Ai\Ai;
use Laravel\Ai\Providers\BedrockProvider;
use Laravel\Ai\Providers\OpenAiProvider;

test('can get an openai provider instance', function () {
    expect(Ai::textProvider('openai'))->toBeInstanceOf(OpenAiProvider::class);
});

test('can get a bedrock provider instance', function () {
    expect(Ai::textProvider('bedrock'))->toBeInstanceOf(BedrockProvider::class);
});

test('provider type is ensured', function () {
    Ai::audioProvider('anthropic');
})->throws(LogicException::class);

test('bedrock provider type is ensured', function () {
    Ai::audioProvider('bedrock');
})->throws(LogicException::class);
