<?php

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
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
})->throws(InvalidArgumentException::class, 'The provider name [anthropic] is already taken.');

test('an on-demand provider cannot take a built-in provider name', function (): void {
    config()->set('ai.providers', []);

    Ai::build(['name' => 'openai', 'driver' => 'anthropic', 'key' => 'tenant-key']);
})->throws(InvalidArgumentException::class, 'The provider name [openai] is already taken.');

test('rebuilding a named on-demand provider uses the new configuration', function (): void {
    Ai::build(['name' => 'tenant', 'driver' => 'anthropic', 'key' => 'old-key']);

    expect(Ai::build(['name' => 'tenant', 'driver' => 'anthropic', 'key' => 'new-key'])->providerCredentials())
        ->toBe(['key' => 'new-key']);
});

test('an on-demand provider keeps its internal flag out of its additional configuration', function (): void {
    expect(Ai::build(['driver' => 'anthropic', 'key' => 'tenant-key', 'url' => 'https://tenant.example.com/v1'])->additionalConfiguration())
        ->toBe(['url' => 'https://tenant.example.com/v1']);
});

test('flushing state forgets on-demand providers but keeps configured providers', function (): void {
    $configured = Ai::textProvider('anthropic');
    $onDemand = Ai::build(['driver' => 'anthropic', 'key' => 'tenant-key']);

    Ai::flushState();

    expect(Ai::textProvider('anthropic'))->toBe($configured)
        ->and(fn () => Ai::textProvider($onDemand->name()))
        ->toThrow(InvalidArgumentException::class, 'was not built in this process');
});

test('on-demand providers are flushed while the queue worker loops', function (): void {
    $provider = Ai::build(['driver' => 'anthropic', 'key' => 'tenant-key']);

    expect(Event::until(new Looping('database', 'default')))->toBeNull()
        ->and(fn () => Ai::textProvider($provider->name()))
        ->toThrow(InvalidArgumentException::class, 'was not built in this process');
});

test('on-demand providers are flushed when an octane operation terminates', function (): void {
    $provider = Ai::build(['driver' => 'anthropic', 'key' => 'tenant-key']);

    Event::dispatch('Laravel\Octane\Contracts\OperationTerminated');

    expect(fn () => Ai::textProvider($provider->name()))
        ->toThrow(InvalidArgumentException::class, 'was not built in this process');
});

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
