<?php

namespace Tests\Fixtures\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Transport\FakeTransporter;

class FakeMcpServer extends Server
{
    protected array $tools = [
        FakeMcpServerTool::class,
        FakeStructuredMcpServerTool::class,
    ];

    public function __construct()
    {
        parent::__construct(new FakeTransporter());
    }
}
