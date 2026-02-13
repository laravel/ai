<?php

namespace Laravel\Ai\Providers\Tools;

class McpServer extends ProviderTool
{
    /**
     * @param  array<string, mixed>|null  $config
     */
    public function __construct(
        public string $name,
        public ?array $config = null,
    ) {}
}
