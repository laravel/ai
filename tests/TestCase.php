<?php

namespace Tests;

use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Prism\Prism\PrismServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            PrismServiceProvider::class,
        ];
    }
}
