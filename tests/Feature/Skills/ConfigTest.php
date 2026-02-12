<?php

namespace Tests\Feature\Skills;

use Orchestra\Testbench\TestCase;

class ConfigTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return ['Laravel\Ai\AiServiceProvider'];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('ai', require __DIR__.'/../../../config/ai.php');
    }

    public function test_config_values_are_retrievable()
    {
        $this->assertEquals('lite', config('ai.skills.default_mode'));
        $this->assertIsArray(config('ai.skills.paths'));
        $this->assertContains(resource_path('skills'), config('ai.skills.paths'));
    }
}
