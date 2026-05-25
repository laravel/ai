<?php

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fixtures\Mcp\FakeMcpClient;
use Tests\Fixtures\Mcp\FakeMcpTool;
use Tests\Fixtures\Mcp\FakeMcpToolResult;

beforeAll(function () {
    if (! class_exists('Laravel\\Mcp\\Client\\Primitives\\Tool')) {
        class_alias(FakeMcpTool::class, 'Laravel\\Mcp\\Client\\Primitives\\Tool');
    }
});

test('agents can return mcp client tools directly', function () {
    $client = new FakeMcpClient;
    $mcpTool = new FakeMcpTool($client, 'search', null, 'Search records.', [
        'type' => 'object',
        'properties' => [
            'query' => [
                'type' => 'string',
            ],
        ],
        'required' => ['query'],
    ]);

    $client->results['search'] = new FakeMcpToolResult([
        ['type' => 'text', 'text' => 'Found results.'],
    ], false);

    $agent = new class($mcpTool) implements Agent, HasTools
    {
        use Promptable;

        public function __construct(public object $tool) {}

        public function instructions(): string
        {
            return 'Use available tools.';
        }

        public function tools(): iterable
        {
            return [$this->tool];
        }
    };

    $agent::fake([
        new ToolCall('call_123', 'search', ['query' => 'laravel']),
        'Done.',
    ]);

    $response = $agent->prompt('Search for Laravel');

    expect($response)
        ->toolCalls->toHaveCount(1)
        ->toolResults->toHaveCount(1);

    expect($response->toolResults->first())->toHaveProperty('result', 'Found results.');

    expect($client)->toHaveProperty(
        'toolCalls',
        [
            ['name' => 'search', 'arguments' => ['query' => 'laravel']],
        ]
    );
});
