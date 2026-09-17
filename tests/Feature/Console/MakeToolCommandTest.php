<?php

test('can create a tool class', function (): void {
    $response = $this->artisan('make:tool', [
        'name' => 'TestTool',
    ]);

    $response->assertExitCode(0)->run();

    expect(app_path('Ai/Tools/TestTool.php'))->toBeFile();
});

test('may publish custom tool stub', function (): void {
    $this->artisan('vendor:publish', [
        '--tag' => 'ai-stubs',
        '--force' => true,
    ])->assertExitCode(0)->run();

    expect(base_path('stubs/tool.stub'))->toBeFile();
});
