<?php

namespace Tests\Feature;

use Laravel\Ai\AiManager;
use Laravel\Ai\Gateway\Cli\ClaudeCliGateway;
use Laravel\Ai\Gateway\Cli\CodexCliGateway;
use Laravel\Ai\Gateway\Cli\GeminiCliGateway;
use Laravel\Ai\Providers\CliProvider;
use Tests\TestCase;

class CliManagerTest extends TestCase
{
    private AiManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->app->make(AiManager::class);
    }

    public function test_create_claude_cli_driver_returns_cli_provider(): void
    {
        $driver = $this->manager->createClaudeCliDriver(['name' => 'claude-cli', 'driver' => 'claude-cli', 'timeout' => 30]);
        $this->assertInstanceOf(CliProvider::class, $driver);
    }

    public function test_create_codex_cli_driver_returns_cli_provider(): void
    {
        $driver = $this->manager->createCodexCliDriver(['name' => 'codex-cli', 'driver' => 'codex-cli', 'timeout' => 30]);
        $this->assertInstanceOf(CliProvider::class, $driver);
    }

    public function test_create_gemini_cli_driver_returns_cli_provider(): void
    {
        $driver = $this->manager->createGeminiCliDriver(['name' => 'gemini-cli', 'driver' => 'gemini-cli', 'timeout' => 30]);
        $this->assertInstanceOf(CliProvider::class, $driver);
    }

    public function test_claude_driver_text_gateway_is_claude_cli_gateway(): void
    {
        $driver = $this->manager->createClaudeCliDriver(['name' => 'claude-cli', 'driver' => 'claude-cli', 'timeout' => 30]);
        $this->assertInstanceOf(ClaudeCliGateway::class, $driver->textGateway());
    }

    public function test_codex_driver_text_gateway_is_codex_cli_gateway(): void
    {
        $driver = $this->manager->createCodexCliDriver(['name' => 'codex-cli', 'driver' => 'codex-cli', 'timeout' => 30]);
        $this->assertInstanceOf(CodexCliGateway::class, $driver->textGateway());
    }

    public function test_gemini_driver_text_gateway_is_gemini_cli_gateway(): void
    {
        $driver = $this->manager->createGeminiCliDriver(['name' => 'gemini-cli', 'driver' => 'gemini-cli', 'timeout' => 30]);
        $this->assertInstanceOf(GeminiCliGateway::class, $driver->textGateway());
    }
}
