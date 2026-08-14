<?php

namespace Tests;

use Laravel\Pulse\PulseServiceProvider;
use Livewire\LivewireServiceProvider;

abstract class PulseTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            LivewireServiceProvider::class,
            PulseServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Livewire encrypts its component snapshots, so rendering a card needs a key...
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }
}
