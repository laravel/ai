<?php

namespace Tests;

use Illuminate\Encryption\EncryptionServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Files\UntrustedUrl;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            EncryptionServiceProvider::class,
            AiServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        UntrustedUrl::resolveUsing(fn (string $host): array => ['93.184.216.34']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
