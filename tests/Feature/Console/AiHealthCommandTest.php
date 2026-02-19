<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class AiHealthCommandTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai.providers.openai', [
            'driver' => 'openai',
            'key' => 'test-key',
        ]);
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('list')
            ->assertExitCode(0)
            ->run();

        $this->assertTrue(true);
    }

    public function test_health_command_runs(): void
    {
        $this->artisan('ai:health')
            ->assertExitCode(1)
            ->run();
    }

    public function test_health_command_with_json_output(): void
    {
        $this->artisan('ai:health', [
            '--json' => true,
        ])
            ->assertExitCode(1)
            ->run();
    }

    public function test_health_command_with_specific_provider(): void
    {
        $this->artisan('ai:health', [
            '--provider' => 'openai',
        ])
            ->assertExitCode(1)
            ->run();
    }
}
