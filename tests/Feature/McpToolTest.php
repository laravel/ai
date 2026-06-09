<?php

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\McpTool;
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
        new ToolCall('call_123', 'mcp_tools_search', ['query' => 'laravel']),
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

test('it skips mcp client tools whose schema cannot be represented', function () {
    $client = new FakeMcpClient;

    $union = new McpTool(new FakeMcpTool($client, 'set_value', null, 'Set a value.', [
        'type' => 'object',
        'properties' => [
            'value' => ['type' => ['string', 'number', 'boolean']],
        ],
        'required' => ['value'],
    ]));

    $client->results['set_value'] = new FakeMcpToolResult([
        ['type' => 'text', 'text' => 'Should not run.'],
    ], false);

    $agent = new class($union) implements Agent, HasTools
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
        new ToolCall('call_skip', 'mcp_tools_set_value', ['value' => 'bug']),
        'Done.',
    ]);

    $response = $agent->prompt('Set the value');

    expect($response->toolResults)->toHaveCount(0);

    expect($client)->toHaveProperty('toolCalls', []);
});

test('it does not over-filter convertible mcp client tools alongside an unrepresentable one', function () {
    $client = new FakeMcpClient;

    $search = new McpTool(new FakeMcpTool($client, 'search', null, 'Search records.', [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string'],
        ],
        'required' => ['query'],
    ]));

    $union = new McpTool(new FakeMcpTool($client, 'set_value', null, 'Set a value.', [
        'type' => 'object',
        'properties' => [
            'value' => ['type' => ['string', 'number', 'boolean']],
        ],
        'required' => ['value'],
    ]));

    $client->results['search'] = new FakeMcpToolResult([
        ['type' => 'text', 'text' => 'Found results.'],
    ], false);

    $agent = new class($search, $union) implements Agent, HasTools
    {
        use Promptable;

        public function __construct(public object $search, public object $union) {}

        public function instructions(): string
        {
            return 'Use available tools.';
        }

        public function tools(): iterable
        {
            return [$this->union, $this->search];
        }
    };

    $agent::fake([
        new ToolCall('call_ok', 'mcp_tools_search', ['query' => 'laravel']),
        'Done.',
    ]);

    $response = $agent->prompt('Search for Laravel');

    expect($response->toolResults)
        ->toHaveCount(1)
        ->first()->toHaveProperty('result', 'Found results.');

    expect($client)->toHaveProperty(
        'toolCalls',
        [
            ['name' => 'search', 'arguments' => ['query' => 'laravel']],
        ]
    );
});
