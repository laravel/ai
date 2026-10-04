<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Facades\Sandbox;
use Laravel\Ai\Sandboxes\DockerProvider;
use Laravel\Ai\Sandboxes\FakeProvider;
use Laravel\Ai\Sandboxes\LocalProvider;

beforeEach(function () {
    Config::set('ai.sandboxes.local.root', $this->root = sys_get_temp_dir().'/ai-sandboxes-'.uniqid());
    Config::set('ai.sandboxes.local.isolate', false);
});

afterEach(fn () => File::deleteDirectory($this->root));

test('the default provider creates, attaches to and deletes sandboxes through the facade', function () {
    $sandbox = Sandbox::create();
    $sandbox->write('notes.txt', 'kept');

    $attached = Sandbox::get($sandbox->id());

    expect(Sandbox::provider())->toBeInstanceOf(LocalProvider::class)
        ->and($attached->provider())->toBe('local')
        ->and($attached->read('notes.txt'))->toBe('kept');

    Sandbox::delete($sandbox->id());

    expect(File::exists("{$this->root}/{$sandbox->id()}"))->toBeFalse();
});

test('providers resolve by configured name and carry that name into handles and locks', function () {
    Config::set('ai.sandboxes.scratch', ['driver' => 'local', 'root' => $this->root, 'isolate' => false]);

    $provider = Sandbox::provider('scratch');
    $sandbox = $provider->create();

    $lock = $provider->lock($sandbox->id(), 10);

    expect($provider->name())->toBe('scratch')
        ->and($sandbox->provider())->toBe('scratch')
        ->and(Sandbox::provider('docker'))->toBeInstanceOf(DockerProvider::class)
        ->and($lock->get())->toBeTrue()
        ->and(Sandbox::provider('scratch')->lock($sandbox->id(), 10)->get())->toBeFalse()
        ->and(Sandbox::provider('local')->lock($sandbox->id(), 10)->get())->toBeTrue();

    $lock->release();
});

test('custom drivers register through the manager', function () {
    $custom = new LocalProvider(['name' => 'custom', 'driver' => 'custom', 'root' => $this->root, 'isolate' => false]);

    Config::set('ai.sandboxes.custom', ['driver' => 'custom']);
    Sandbox::extend('custom', fn ($app, array $config) => $custom);

    expect(Sandbox::provider('custom'))->toBe($custom);
});

test('faking replaces every provider', function () {
    $fake = Sandbox::fake();

    expect(Sandbox::provider())->toBe($fake)
        ->and(Sandbox::provider('docker'))->toBe($fake)
        ->and(Sandbox::create()->provider())->toBe('fake')
        ->and($fake)->toBeInstanceOf(FakeProvider::class);
});
