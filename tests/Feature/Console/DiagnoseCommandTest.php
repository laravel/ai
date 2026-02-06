<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class DiagnoseCommandTest extends TestCase
{
    public function test_reports_error_on_failing_provider(): void
    {
        config()->set('ai.providers', [
            'foo' => [
                'driver' => 'bar',
                'key' => null,
            ]
        ]);

        $this->artisan('ai:diagnose', [
            '--skip-requests' => true,
        ])
            ->expectsOutputToContain('Unable to resolve provider')
            ->assertExitCode(0)
            ->run();
    }

    public function test_runs_minimal_requests_for_supported_providers(): void
    {
        $this->artisan('ai:diagnose', [
            '--provider' => ['openai'],
        ])
            ->expectsOutputToContain('OK')
            ->assertExitCode(0)
            ->run();
    }
}
