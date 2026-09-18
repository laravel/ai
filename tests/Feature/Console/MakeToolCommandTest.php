<?php

test('can create a tool class', function (): void {
    $response = $this->artisan('make:tool', [
        'name' => 'TestTool',
    ]);

    $response->assertExitCode(0)->run();

    expect(app_path('Ai/Tools/TestTool.php'))->toBeFile();
});
