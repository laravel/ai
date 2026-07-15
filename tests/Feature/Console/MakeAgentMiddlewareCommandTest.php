<?php

test('can create an agent middleware class', function (): void {
    $response = $this->artisan('make:agent-middleware', [
        'name' => 'TestMiddleware',
    ]);

    $response->assertExitCode(0)->run();

    expect(app_path('Ai/Middleware/TestMiddleware.php'))->toBeFile();

    $contents = file_get_contents(app_path('Ai/Middleware/TestMiddleware.php'));

    expect($contents)->toContain('handle(PendingStep $step, Closure $next)')
        ->and($contents)->not->toContain('AgentPrompt');
});

test('may publish custom middleware stub', function (): void {
    $this->artisan('vendor:publish', [
        '--tag' => 'ai-stubs',
        '--force' => true,
    ])->assertExitCode(0)->run();

    expect(base_path('stubs/agent-middleware.stub'))->toBeFile();
});
