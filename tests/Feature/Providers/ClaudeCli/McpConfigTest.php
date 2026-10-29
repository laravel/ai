<?php

use Laravel\Ai\Gateway\ClaudeCli\ClaudeCliGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Tests\Feature\Providers\ClaudeCli\ClaudeCliHelpers;

uses(ClaudeCliHelpers::class);

afterEach(function (): void {
    $this->cleanUpFakeCliScripts();
    // Restore default config
    config(['ai.providers.claude-cli.mcp_url' => null, 'ai.providers.claude-cli.mcp_servers' => []]);
    app(\Laravel\Ai\AiManager::class)->forgetInstance('claude-cli');
});

describe('MCP config assembly', function (): void {
    test('no --mcp-config flag is passed when no MCP servers are configured', function (): void {
        [$path, $argsFile] = $this->fakeCliCapturingArgs($this->cliSuccessLines());
        $this->useCliBinary($path);

        $gateway = new ClaudeCliGateway(app(\Illuminate\Contracts\Events\Dispatcher::class));
        $provider = app(\Laravel\Ai\AiManager::class)->textProvider('claude-cli');

        $gen = $gateway->generateStreamStep('inv', $provider, 'claude-sonnet-5-5', null, [], [], null, null, null, new StepContext);
        foreach ($gen as $e) {}

        $args = trim(file_get_contents($argsFile));

        expect($args)->not->toContain('--mcp-config');
    });

    test('a single mcp_url in config produces a -static key in the MCP JSON', function (): void {
        [$path, $argsFile] = $this->fakeCliCapturingArgs($this->cliSuccessLines());
        config([
            'ai.providers.claude-cli.cli_binary' => $path,
            'ai.providers.claude-cli.mcp_url' => 'http://localhost:9000/sse',
        ]);
        app(\Laravel\Ai\AiManager::class)->forgetInstance('claude-cli');

        $gateway = new ClaudeCliGateway(app(\Illuminate\Contracts\Events\Dispatcher::class));
        $provider = app(\Laravel\Ai\AiManager::class)->textProvider('claude-cli');

        $gen = $gateway->generateStreamStep('inv', $provider, 'claude-sonnet-5-5', null, [], [], null, null, null, new StepContext);
        foreach ($gen as $e) {}

        $args = trim(file_get_contents($argsFile));

        expect($args)->toContain('--mcp-config');

        // The MCP JSON is passed as a single argument — extract it
        preg_match('/--mcp-config (\{[^)]+\})/', $args, $m);
        $json = json_decode($m[1] ?? '{}', true);

        expect($json)->toHaveKey('mcpServers')
            ->and($json['mcpServers'])->toHaveKey('claude-cli-static')
            ->and($json['mcpServers']['claude-cli-static']['url'])->toBe('http://localhost:9000/sse');
    });

    test('per-call mcp_servers in providerOptions produce -call-n keys', function (): void {
        [$path, $argsFile] = $this->fakeCliCapturingArgs($this->cliSuccessLines());
        $this->useCliBinary($path);

        $gateway = new ClaudeCliGateway(app(\Illuminate\Contracts\Events\Dispatcher::class));
        $provider = app(\Laravel\Ai\AiManager::class)->textProvider('claude-cli');

        $options = new TextGenerationOptions(providerOptions: [
            'mcp_servers' => [
                ['type' => 'sse', 'url' => 'http://call-server-1/sse'],
                ['type' => 'sse', 'url' => 'http://call-server-2/sse'],
            ],
        ]);

        $gen = $gateway->generateStreamStep('inv', $provider, 'claude-sonnet-5-5', null, [], [], null, $options, null, new StepContext);
        foreach ($gen as $e) {}

        $args = trim(file_get_contents($argsFile));

        expect($args)->toContain('--mcp-config');

        preg_match('/--mcp-config (\{[^)]+\})/', $args, $m);
        $json = json_decode($m[1] ?? '{}', true);

        expect($json['mcpServers'])->toHaveKey('claude-cli-call-1')
            ->and($json['mcpServers'])->toHaveKey('claude-cli-call-2')
            ->and($json['mcpServers']['claude-cli-call-1']['url'])->toBe('http://call-server-1/sse')
            ->and($json['mcpServers']['claude-cli-call-2']['url'])->toBe('http://call-server-2/sse');
    });
});
