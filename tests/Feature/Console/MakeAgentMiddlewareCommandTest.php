<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class MakeAgentMiddlewareCommandTest extends TestCase
{
    public function test_can_create_an_agent_middleware_class(): void
    {
        $response = $this->artisan('make:agent-middleware', [
            'name' => 'TestMiddleware',
        ]);

        $response->assertExitCode(0)->run();

        $this->assertFileExists(app_path('Ai/Middleware/TestMiddleware.php'));
    }

    public function test_may_publish_custom_middleware_stub(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'ai-stubs',
            '--force' => true,
        ])->assertExitCode(0)->run();

        $this->assertFileExists(base_path('stubs/middleware.stub'));
    }
}
