<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class MakeAgentCommandTest extends TestCase
{
    public function test_can_create_an_agent_class(): void
    {
        $response = $this->artisan('make:agent', [
            'name' => 'TestAgent',
        ]);

        $response->assertExitCode(0)->run();

        $this->assertFileExists(app_path('Ai/Agents/TestAgent.php'));
    }

    public function test_can_create_a_structured_agent_class(): void
    {
        $response = $this->artisan('make:agent', [
            'name' => 'StructuredAgent',
            '--structured' => true,
        ]);

        $response->assertExitCode(0)->run();

        $this->assertFileExists(app_path('Ai/Agents/StructuredAgent.php'));
    }

    public function test_may_publish_custom_agent_stubs(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'ai-stubs',
            '--force' => true,
        ])->assertExitCode(0)->run();

        $this->assertFileExists(base_path('stubs/ai/agent.stub'));
        $this->assertFileExists(base_path('stubs/ai/structured-agent.stub'));
    }
}