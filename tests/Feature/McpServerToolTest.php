<?php

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fixtures\Mcp\FakeMcpServerTool;

test('agents can return mcp server tools directly', function (): void {
    $serverTool = new FakeMcpServerTool;

    $agent = new class($serverTool) implements Agent, HasTools
    {
        use Promptable;

        public function __construct(public object $tool) {}

        public function instructions(): string
        {
            return 'Use available tools.';
        }

        public function tools(Lab|string $provider): iterable
        {
            return [$this->tool];
        }
    };

    $agent::fake([
        new ToolCall('call_123', 'fake-mcp-server-tool', ['city' => 'Paris']),
        'Done.',
    ]);

    $response = $agent->prompt('What is the weather in Paris?');

    expect($response)
        ->toolCalls->toHaveCount(1)
        ->toolResults->toHaveCount(1);

    expect($response->toolResults->first())->toHaveProperty('result', 'Sunny in Paris.');

    expect($serverTool->invocations)->toBe([['city' => 'Paris']]);
});
