<?php

test('can create an agent class', function () {
    $response = $this->artisan('make:agent', [
        'name' => 'TestAgent',
    ]);

    $response->assertExitCode(0)->run();

    expect(app_path('Ai/Agents/TestAgent.php'))->toBeFile();
});

test('can create a structured agent class', function () {
    $response = $this->artisan('make:agent', [
        'name' => 'StructuredAgent',
        '--structured' => true,
    ]);

    $response->assertExitCode(0)->run();

    expect(app_path('Ai/Agents/StructuredAgent.php'))->toBeFile();
});

test('may publish custom agent stubs', function () {
    $this->artisan('vendor:publish', [
        '--tag' => 'ai-stubs',
        '--force' => true,
    ])->assertExitCode(0)->run();

    expect(base_path('stubs/agent.stub'))->toBeFile();
    expect(base_path('stubs/structured-agent.stub'))->toBeFile();
});
