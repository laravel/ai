<?php

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\McpServer;
use Tests\Fixtures\Mcp\FakeMcpServer;

test('agents can spread an mcp server wrapper to expose its tools like remote clients', function () {
    $agent = new class implements Agent, HasTools
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Use available tools.';
        }

        public function tools(): iterable
        {
            return [
                ...McpServer::make(FakeMcpServer::class),
                // mix with regular tools if desired
            ];
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
});

test('agents can list an mcp server class directly (no spread) and we auto-wrap its tools', function () {
    $agent = new class implements Agent, HasTools
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Use available tools.';
        }

        public function tools(): iterable
        {
            return [
                FakeMcpServer::class,
            ];
        }
    };

    $agent::fake([
        new ToolCall('call_123', 'fake-structured-mcp-server-tool', ['city' => 'Paris']),
        'Done.',
    ]);

    $response = $agent->prompt('Get structured weather');

    expect($response)
        ->toolCalls->toHaveCount(1)
        ->toolResults->toHaveCount(1);

    $result = $response->toolResults->first()->result;
    expect(json_decode($result, true))->toMatchArray([
        'temperature' => 72,
        'conditions' => 'Sunny',
    ]);
});

test('agents can list a raw mcp server instance directly', function () {
    $agent = new class implements Agent, HasTools
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Use available tools.';
        }

        public function tools(): iterable
        {
            return [
                new FakeMcpServer,
            ];
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
});
