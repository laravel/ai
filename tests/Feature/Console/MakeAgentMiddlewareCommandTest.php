<?php

test('can create an agent middleware class', function (): void {
    $response = $this->artisan('make:agent-middleware', [
        'name' => 'TestMiddleware',
    ]);

    $response->assertExitCode(0)->run();

    expect(app_path('Ai/Middleware/TestMiddleware.php'))->toBeFile()
        ->and(file_get_contents(app_path('Ai/Middleware/TestMiddleware.php')))->toContain('handle(PendingStep $step, Closure $next)');
});
