<?php

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Providers\OpenAiProvider;

test('can get an openai provider instance', function () {
    expect(Ai::textProvider('openai'))->toBeInstanceOf(OpenAiProvider::class);
});

test('provider type is ensured', function () {
    Ai::audioProvider('anthropic');
})->throws(LogicException::class);

test('driver extensions survive between queue jobs', function () {
    config()->set('ai.providers.custom', ['driver' => 'custom']);

    Ai::extend('custom', fn ($app, array $config) => new OpenAiProvider(
        $app->make(OpenAiGateway::class),
        $config,
        $app->make(Dispatcher::class),
    ));

    $runJobOnFreshScope = function () {
        app()->forgetScopedInstances();
        Facade::clearResolvedInstances();

        return Ai::textProvider('custom');
    };

    expect($runJobOnFreshScope())->toBeInstanceOf(OpenAiProvider::class);
    expect($runJobOnFreshScope())->toBeInstanceOf(OpenAiProvider::class);
});
