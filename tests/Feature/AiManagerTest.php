<?php

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Providers\OpenAiProvider;

test('can get an openai provider instance', function (): void {
    expect(Ai::textProvider('openai'))->toBeInstanceOf(OpenAiProvider::class);
});

test('provider type is ensured', function (): void {
    Ai::audioProvider('anthropic');
})->throws(LogicException::class);

test('a configured provider casts to its driver', function (): void {
    config()->set('ai.providers.cloudflare', ['driver' => 'openai-compatible', 'url' => 'https://example.com/v1']);

    expect((string) Ai::textProvider('cloudflare'))->toBe('openai-compatible');
});

test('an on-demand provider cannot replace a configured provider', function (): void {
    Ai::build(['name' => 'anthropic', 'driver' => 'anthropic', 'key' => 'tenant-key']);
})->throws(InvalidArgumentException::class, 'Provider [anthropic] is already configured.');

test('driver extensions survive between queue jobs', function (): void {
    config()->set('ai.providers.custom', ['driver' => 'custom']);

    Ai::extend('custom', fn ($app, array $config): OpenAiProvider => new OpenAiProvider(
        $app->make(OpenAiGateway::class),
        $config,
        $app->make(Dispatcher::class),
    ));

    $runJobOnFreshScope = function (): TextProvider {
        app()->forgetScopedInstances();
        Facade::clearResolvedInstances();

        return Ai::textProvider('custom');
    };

    expect($runJobOnFreshScope())->toBeInstanceOf(OpenAiProvider::class);
    expect($runJobOnFreshScope())->toBeInstanceOf(OpenAiProvider::class);
});
