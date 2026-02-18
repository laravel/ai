<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class MakeSubAgentCommandTest extends TestCase
{
    public function test_can_create_a_subagent_class(): void
    {
        $response = $this->artisan('make:subagent', [
            'name' => 'TestSubAgent',
        ]);

        $response->assertExitCode(0)->run();

        $this->assertFileExists(app_path('Ai/Agents/TestSubAgent.php'));
    }

    public function test_may_publish_custom_subagent_stub(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'ai-stubs',
            '--force' => true,
        ])->assertExitCode(0)->run();

        $this->assertFileExists(base_path('stubs/subagent.stub'));
    }
}
