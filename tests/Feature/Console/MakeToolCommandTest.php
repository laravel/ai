<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class MakeToolCommandTest extends TestCase
{
    public function test_can_create_a_tool_class(): void
    {
        $response = $this->artisan('make:tool', [
            'name' => 'TestTool',
        ]);

        $response->assertExitCode(0)->run();

        $this->assertFileExists(app_path('Ai/Tools/TestTool.php'));
    }

    public function test_may_publish_custom_tool_stub(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'ai-stubs',
            '--force' => true,
        ])->assertExitCode(0)->run();

        $this->assertFileExists(base_path('stubs/ai/tool.stub'));
    }
}
